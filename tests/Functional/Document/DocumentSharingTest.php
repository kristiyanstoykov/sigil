<?php

declare(strict_types=1);

namespace App\Tests\Functional\Document;

use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Enum\DocumentVersionKind;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentSharer;
use App\Document\Service\DocumentStorageInterface;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
use App\Tests\Functional\AuthWebTestCase;

/**
 * Sharing re-wraps the DEK, it never re-encrypts the file (ADR-004): a recipient
 * gets their own wrapped copy of the same key, the ciphertext is untouched, and
 * revoking deletes the grant rows that hold those copies.
 */
final class DocumentSharingTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    private const SIGNED_PDF = self::MINIMAL_PDF."\n% pretend this one carries a signature";

    private function uploader(): DocumentUploader
    {
        return static::getContainer()->get(DocumentUploader::class);
    }

    private function downloader(): DocumentDownloader
    {
        return static::getContainer()->get(DocumentDownloader::class);
    }

    private function sharer(): DocumentSharer
    {
        return static::getContainer()->get(DocumentSharer::class);
    }

    private function grants(): DocumentKeyGrantRepository
    {
        return static::getContainer()->get(DocumentKeyGrantRepository::class);
    }

    /** Mints a Signed version the way the signing flow does. */
    private function addSignedVersion(Document $document, \App\Core\Entity\User $actor): void
    {
        static::getContainer()->get(DocumentVersionWriter::class)->write(
            $document,
            $actor,
            self::SIGNED_PDF,
            DocumentVersionKind::Signed,
            'document.signed',
        );
    }

    public function testShareLetsTheRecipientDecryptWithoutTouchingTheCiphertext(): void
    {
        $owner = $this->createUser($this->uniqueEmail('shareowner'));
        $recipient = $this->createUser($this->uniqueEmail('sharerecipient'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, 'Contract.pdf');
        $version = $document->getLatestVersion();
        self::assertNotNull($version);

        $storage = static::getContainer()->get(DocumentStorageInterface::class);
        $keyBefore = $version->getStorageKey();
        $cipherBefore = $storage->retrieve($keyBefore);
        $hashBefore = $version->getContentHash();

        // Before sharing there is no key for the recipient at all.
        self::assertNull($this->grants()->findForVersionAndUser($version, $recipient));

        $this->sharer()->share($document, $owner, $recipient);

        // The recipient now has their OWN grant - a different wrapped envelope of
        // the same DEK, since it is wrapped under their KEK, not the owner's.
        $ownerGrant = $this->grants()->findForVersionAndUser($version, $owner);
        $recipientGrant = $this->grants()->findForVersionAndUser($version, $recipient);
        self::assertNotNull($ownerGrant);
        self::assertNotNull($recipientGrant);
        self::assertNotSame($ownerGrant->getWrappedDek(), $recipientGrant->getWrappedDek());

        // Both decrypt to the identical plaintext.
        self::assertSame(self::MINIMAL_PDF, $this->downloader()->download($version, $recipient));
        self::assertSame(self::MINIMAL_PDF, $this->downloader()->download($version, $owner));

        // Re-wrap, not re-encrypt: same storage key, same bytes, same hash.
        self::assertSame($keyBefore, $version->getStorageKey());
        self::assertSame($cipherBefore, $storage->retrieve($version->getStorageKey()));
        self::assertSame($hashBefore, $version->getContentHash());
    }

    public function testRevokeRemovesTheKeyAndTheOwnerKeepsAccess(): void
    {
        $owner = $this->createUser($this->uniqueEmail('revokeowner'));
        $recipient = $this->createUser($this->uniqueEmail('revokerecipient'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, 'Secret.pdf');
        $version = $document->getLatestVersion();
        self::assertNotNull($version);

        $this->sharer()->share($document, $owner, $recipient);
        self::assertSame(self::MINIMAL_PDF, $this->downloader()->download($version, $recipient));

        $this->sharer()->revoke($document, $owner, $recipient);

        self::assertNull($this->grants()->findForVersionAndUser($version, $recipient));
        self::assertSame(self::MINIMAL_PDF, $this->downloader()->download($version, $owner));

        $this->expectException(DomainException::class);
        $this->downloader()->download($version, $recipient);
    }

    public function testAccessCarriesForwardToVersionsMintedAfterTheShare(): void
    {
        $owner = $this->createUser($this->uniqueEmail('carryowner'));
        $recipient = $this->createUser($this->uniqueEmail('carryrecipient'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, 'Agreement.pdf');
        $this->sharer()->share($document, $owner, $recipient);

        // Owner signs afterwards: the new version must be readable by BOTH, or
        // signing would silently redraw who can see the document.
        $this->addSignedVersion($document, $owner);
        $signed = $document->getLatestVersion();
        self::assertNotNull($signed);
        self::assertSame(DocumentVersionKind::Signed, $signed->getKind());

        self::assertSame(self::SIGNED_PDF, $this->downloader()->download($signed, $owner));
        self::assertSame(self::SIGNED_PDF, $this->downloader()->download($signed, $recipient));
    }

    public function testAVersionWrittenByARecipientStaysReadableByTheOwner(): void
    {
        $owner = $this->createUser($this->uniqueEmail('backowner'));
        $recipient = $this->createUser($this->uniqueEmail('backrecipient'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, 'Countersign.pdf');
        $this->sharer()->share($document, $owner, $recipient);

        // The recipient is the actor this time (what counter-signing will do).
        $this->addSignedVersion($document, $recipient);
        $signed = $document->getLatestVersion();
        self::assertNotNull($signed);

        self::assertSame(self::SIGNED_PDF, $this->downloader()->download($signed, $owner));
        self::assertSame(self::SIGNED_PDF, $this->downloader()->download($signed, $recipient));
    }

    public function testRevokedAccessIsNotRestoredByTheNextVersion(): void
    {
        $owner = $this->createUser($this->uniqueEmail('gonewner'));
        $recipient = $this->createUser($this->uniqueEmail('gonerecipient'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, 'Revoked.pdf');
        $this->sharer()->share($document, $owner, $recipient);
        $this->sharer()->revoke($document, $owner, $recipient);

        $this->addSignedVersion($document, $owner);
        $signed = $document->getLatestVersion();
        self::assertNotNull($signed);

        self::assertNull($this->grants()->findForVersionAndUser($signed, $recipient));

        $this->expectException(DomainException::class);
        $this->downloader()->download($signed, $recipient);
    }

    public function testOnlyTheOwnerCanShareOrRevoke(): void
    {
        $owner = $this->createUser($this->uniqueEmail('guardowner'));
        $recipient = $this->createUser($this->uniqueEmail('guardrecipient'));
        $stranger = $this->createUser($this->uniqueEmail('guardstranger'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, 'Guarded.pdf');
        $this->sharer()->share($document, $owner, $recipient);

        // A recipient cannot pass access on to someone else.
        $this->expectException(DomainException::class);
        $this->sharer()->share($document, $recipient, $stranger);
    }

    public function testSharingTwiceIsRefusedAndTheOwnerCannotBeARecipient(): void
    {
        $owner = $this->createUser($this->uniqueEmail('dupowner'));
        $recipient = $this->createUser($this->uniqueEmail('duprecipient'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, 'Once.pdf');
        $this->sharer()->share($document, $owner, $recipient);

        try {
            $this->sharer()->share($document, $owner, $recipient);
            self::fail('Sharing the same document twice should be refused.');
        } catch (DomainException) {
        }

        $this->expectException(DomainException::class);
        $this->sharer()->share($document, $owner, $owner);
    }

    public function testTheAccessListIsReadableFromTheGrants(): void
    {
        $owner = $this->createUser($this->uniqueEmail('listowner'));
        $recipient = $this->createUser($this->uniqueEmail('listrecipient'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, 'Listed.pdf');
        $documents = static::getContainer()->get(DocumentRepository::class);

        self::assertSame([], $documents->findSharedWith($recipient));
        self::assertSame([], $this->grants()->findRecipientsForDocument($document));

        $this->sharer()->share($document, $owner, $recipient);

        // Shows up for the recipient, but never in their own documents list.
        $shared = $documents->findSharedWith($recipient);
        self::assertCount(1, $shared);
        self::assertSame($document->getId()->toRfc4122(), $shared[0]->getId()->toRfc4122());
        self::assertSame([], $documents->findByOwner($recipient));

        // The owner sees exactly one recipient, and is not listed themselves.
        $recipients = $this->grants()->findRecipientsForDocument($document);
        self::assertCount(1, $recipients);
        self::assertSame($recipient->getEmail(), $recipients[0]->getEmail());

        $this->sharer()->revoke($document, $owner, $recipient);
        self::assertSame([], $documents->findSharedWith($recipient));
        self::assertSame([], $this->grants()->findRecipientsForDocument($document));
    }
}
