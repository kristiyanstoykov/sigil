<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Repository\AccountClosureRecordRepository;
use App\Account\Repository\ArchivedUserKeyRepository;
use App\Account\Service\AccountDeleter;
use App\Account\Service\ArchiveKeyWrapper;
use App\Account\Service\ClosureRecordSealer;
use App\Account\Service\RecoveryCode;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Auth\Security\EmailFingerprinter;
use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Entity\Certificate;
use App\Certificate\Service\PinGate;
use App\Certificate\Service\PinHasher;
use App\Certificate\Service\SuiteCredentials;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Core\Repository\UserRepository;
use App\Delivery\Service\RecipientEligibility;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Repository\UserEncryptionKeyRepository;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
use App\Document\Service\KeyManagementService;
use App\Notification\Enum\NotificationType;
use App\Notification\Repository\NotificationRepository;
use App\Notification\Service\Notifier;
use App\Signing\Enum\SigningRequestStatus;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\DocumentSigner;
use App\Signing\Service\NoTsaProvider;
use App\Signing\Service\PadesSignerInterface;
use App\Signing\Service\PadesSignRequest;
use App\Signing\Service\SigningRequestService;
use App\Signing\Service\TsaProviderRegistry;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Account deletion, ADR-015 §1-3: what closes, what is revoked, what is erased,
 * and what is kept under the archive key. Real services and the real archive
 * token; a fake PAdES signer so no user token is needed.
 */
final class AccountDeleterTest extends AuthWebTestCase
{
    private const PIN = '135790';
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testTheRowIsTombstonedAndTheKekArchivedUnchanged(): void
    {
        $user = $this->createUser($email = $this->uniqueEmail('leaver'));
        $this->upload($user);
        $kek = $this->get(KeyManagementService::class)->userKek($user);

        $summary = $this->deleter()->delete($user);

        self::assertTrue($user->isDeleted());
        self::assertSame('deleted-'.$user->getId()->toRfc4122().'@invalid', $user->getEmail());
        self::assertSame($this->get(EmailFingerprinter::class)->fingerprint(strtoupper($email)), $user->getEmailFingerprint());
        self::assertTrue($this->get(PinHasher::class)->verify(RecoveryCode::normalize($summary->recoveryCode), (string) $user->getRecoveryCodeHash()));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{5}(-[A-Z2-7]{5}){3}$/', $summary->recoveryCode);

        self::assertNull($this->get(UserEncryptionKeyRepository::class)->findForUser($user));
        $archived = $this->get(ArchivedUserKeyRepository::class)->findForUser($user);
        self::assertNotNull($archived);
        self::assertSame($kek, $this->get(ArchiveKeyWrapper::class)->unwrapKek(
            (string) base64_decode($archived->getWrappedKek(), true),
            AccountDeleter::archiveAad($user),
        ));
        self::assertEquals($summary->deletedAt->modify('+10 years'), $archived->getRetainUntil());

        $entries = $this->get(AuditLogEntryRepository::class)->findBy(['action' => 'account.deleted', 'subjectId' => $user->getId()->toRfc4122()]);
        self::assertCount(1, $entries);
    }

    public function testAClosureRecordIsSealedUnderTheKekBeforeItIsArchived(): void
    {
        $user = $this->createUser($this->uniqueEmail('leaver'));
        $summary = $this->deleter()->delete($user);

        $records = $this->get(AccountClosureRecordRepository::class)->findForUser($user);
        self::assertCount(1, $records);
        self::assertEquals($summary->deletedAt, $records[0]->getSealedAt());
        $entry = $this->get(AuditLogEntryRepository::class)->findOneBy(['action' => 'account.deleted', 'subjectId' => $user->getId()->toRfc4122()]);
        self::assertSame($records[0]->getId()->toRfc4122(), $entry?->getPayload()['closureRecordId'] ?? null);

        // Unreadable while the account is deleted; the restore test reads it back.
        $this->expectException(DomainException::class);
        $this->get(ClosureRecordSealer::class)->read($records[0], $user);
    }

    public function testNoKeyIsEverMintedForADeletedAccount(): void
    {
        $user = $this->createUser($this->uniqueEmail('leaver'));
        $this->deleter()->delete($user);

        $this->expectException(DomainException::class);
        $this->get(KeyManagementService::class)->userKek($user);
    }

    public function testUnsharedDraftsAreErasedAndSignedDocumentsKept(): void
    {
        $user = $this->createUser($this->uniqueEmail('leaver'));
        $certificate = $this->makeCertificate($user);
        $draft = $this->upload($user);
        $signed = $this->upload($user);
        $this->signer()->sign($signed, $certificate, $user, self::PIN);
        $draftId = $draft->getId();

        $summary = $this->deleter()->delete($user);

        self::assertSame(1, $summary->draftsErased);
        self::assertSame(1, $summary->documentsRetained);
        self::assertNull($this->get(DocumentRepository::class)->find($draftId));
        self::assertNotNull($this->get(DocumentRepository::class)->find($signed->getId()));
    }

    public function testCertificatesAreRevokedAsCessationOfOperation(): void
    {
        $user = $this->createUser($this->uniqueEmail('leaver'));
        $certificate = $this->makeCertificate($user);

        $summary = $this->deleter()->delete($user);

        self::assertSame([$certificate->getSerialNumber()], $summary->revokedSerials);
        self::assertNotNull($certificate->getRevokedAt());
        self::assertSame(Certificate::REVOCATION_CESSATION_OF_OPERATION, $certificate->getRevocationReason());
    }

    public function testARequestTheyStartedIsWithdrawn(): void
    {
        [$owner, $first] = [$this->signerUser('owner'), $this->signerUser('first')];
        $request = $this->requests()->create($this->upload($owner), $owner, [$first], $this->inDays(7));

        $summary = $this->deleter()->delete($owner);

        self::assertSame(SigningRequestStatus::Cancelled, $request->getStatus());
        self::assertSame([$request->getId()->toRfc4122() => 'cancelled'], $summary->closedRequests);
    }

    public function testARequestStillWaitingOnThemClosesAtAnyPosition(): void
    {
        [$owner, $first, $second] = [$this->signerUser('owner'), $this->signerUser('first'), $this->signerUser('second')];
        $document = $this->upload($owner);
        $request = $this->requests()->create($document, $owner, [$first, $second], $this->inDays(7));

        // The second signer leaves while the turn is still with the first.
        $this->deleter()->delete($second);

        self::assertSame(SigningRequestStatus::SignerDeleted, $request->getStatus());
        self::assertNotNull($request->signerFor($second)?->getAccountDeletedAt());
        self::assertFalse($document->isAwaitingSignatures());
        // The turn holder loses the key the turn gave them, as on any close.
        self::assertFalse($this->get(DocumentKeyGrantRepository::class)->hasGrantForDocument($document, $first));
    }

    public function testARequestTheyAlreadySignedCarriesOnWithoutThem(): void
    {
        [$owner, $first, $second] = [$this->signerUser('owner'), $this->signerUser('first'), $this->signerUser('second')];
        $document = $this->upload($owner);
        $request = $this->requests()->create($document, $owner, [$first, $second], $this->inDays(7));
        $this->signer()->sign($document, $this->certificateOf($first), $first, self::PIN);

        $summary = $this->deleter()->delete($first);

        self::assertSame([], $summary->closedRequests);
        self::assertTrue($request->isPending());

        // The next version is granted to everyone but the archived account.
        $this->signer()->sign($document, $this->certificateOf($second), $second, self::PIN);

        self::assertSame(SigningRequestStatus::Completed, $request->getStatus());
        $latest = $document->getLatestVersion();
        self::assertNotNull($latest);
        self::assertNull($this->get(DocumentKeyGrantRepository::class)->findForVersionAndUser($latest, $first));
    }

    public function testADeletedAccountCanNoLongerBeFoundOrServed(): void
    {
        $user = $this->createUser($email = $this->uniqueEmail('leaver'));
        $this->deleter()->delete($user);

        self::assertNull($this->get(UserRepository::class)->findOneByEmail($email));
        self::assertNull($this->get(UserRepository::class)->findOneByEmail($user->getEmail()));
        self::assertSame('That account has been deleted.', $this->get(RecipientEligibility::class)->reasonWhyNot($user));
    }

    public function testTheInboxIsForgotten(): void
    {
        $user = $this->createUser($this->uniqueEmail('leaver'));
        $this->get(Notifier::class)->notify(
            recipient: $user,
            type: NotificationType::DataExportReady,
            title: 'An old line',
            url: '/',
        );

        $this->deleter()->delete($user);

        self::assertSame(0, $this->get(NotificationRepository::class)->countFor($user));
    }

    public function testAnAccountIsDeletedOnce(): void
    {
        $user = $this->createUser($this->uniqueEmail('leaver'));
        $this->deleter()->delete($user);

        $this->expectException(DomainException::class);
        $this->deleter()->delete($user);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function get(string $id): object
    {
        /** @var T */
        return static::getContainer()->get($id);
    }

    private function deleter(): AccountDeleter
    {
        return $this->get(AccountDeleter::class);
    }

    private function requests(): SigningRequestService
    {
        return $this->get(SigningRequestService::class);
    }

    private function signerUser(string $role): User
    {
        $user = $this->createUser($this->uniqueEmail($role));
        $this->makeCertificate($user);

        return $user;
    }

    private function certificateOf(User $user): Certificate
    {
        $certificate = $this->get(EntityManagerInterface::class)->getRepository(Certificate::class)->findOneBy(['user' => $user]);
        self::assertNotNull($certificate);

        return $certificate;
    }

    private function upload(User $owner): Document
    {
        return $this->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, 'Contract.pdf');
    }

    private function inDays(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d days', $days));
    }

    private function signer(): DocumentSigner
    {
        $c = static::getContainer();
        $caDir = sys_get_temp_dir().'/sigil-deleter-test-ca';
        @mkdir($caDir);
        file_put_contents($caDir.'/ca.crt', "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");

        $fake = new class implements PadesSignerInterface {
            public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
            {
                return $request->pdfBytes."\n% signed";
            }
        };

        return new DocumentSigner(
            $c->get(PinGate::class),
            $c->get(DocumentDownloader::class),
            $fake,
            new TsaProviderRegistry([new NoTsaProvider()], 'none'),
            $c->get(DocumentVersionWriter::class),
            $c->get(SigningRequestRepository::class),
            $c->get(SigningRequestService::class),
            $c->get(EventDispatcherInterface::class),
            $c->get(EntityManagerInterface::class),
            $c->get(SignatureAlgorithmRegistry::class),
            new SuiteCredentials($caDir),
        );
    }

    private function makeCertificate(User $user): Certificate
    {
        $em = $this->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $certificate = new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Test Signer',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'test-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash(self::PIN, \PASSWORD_ARGON2ID),
        );
        $em->persist($certificate);
        $em->flush();

        return $certificate;
    }
}
