<?php

declare(strict_types=1);

namespace App\Tests\Functional\Delivery;

use App\Certificate\Entity\Certificate;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Delivery\Repository\DeliveryRepository;
use App\Delivery\Service\DeliveryService;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentUploader;
use App\Receipt\Enum\ReceiptOutcome;
use App\Receipt\Enum\ReceiptSource;
use App\Receipt\Repository\DeliveryReceiptRepository;
use App\Receipt\Service\ReceiptSealer;
use App\Signing\Service\SigningRequestService;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Delivery: being served a document (ADR-012). Nothing is asked of a recipient,
 * nothing can be refused, and the sender gets a sealed receipt.
 */
class DeliveryTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testEveryRecipientIsServedAtOnceAndCanReadItImmediately(): void
    {
        [$sender, $first, $second] = $this->threeUsers();
        $document = $this->upload($sender);

        $delivery = $this->service()->deliver($document, $sender, [$first, $second], 'For your records.');

        // No turn and no order: both hold the key from the same moment.
        $grants = static::getContainer()->get(DocumentKeyGrantRepository::class);
        self::assertTrue($grants->hasGrantForDocument($document, $first));
        self::assertTrue($grants->hasGrantForDocument($document, $second));
        self::assertCount(2, $delivery->getRecipients());
        self::assertSame('For your records.', $delivery->getNote());

        // Served means readable, not notified: the plaintext comes back.
        $version = $document->getLatestVersion();
        self::assertNotNull($version);
        $bytes = static::getContainer()->get(DocumentDownloader::class)->download($version, $second);
        self::assertSame(self::MINIMAL_PDF, $bytes);
    }

    public function testDeliveryIsAttestedByASealedReceiptAddressedToEveryone(): void
    {
        if (!static::getContainer()->get(ReceiptSealer::class)->isReady()) {
            self::markTestSkipped('Run sigil:ca:init and sigil:seal:init first.');
        }

        [$sender, $recipient] = $this->threeUsers();
        $document = $this->upload($sender);

        $delivery = $this->service()->deliver($document, $sender, [$recipient]);

        $receipt = static::getContainer()->get(DeliveryReceiptRepository::class)
            ->findForSource(ReceiptSource::Delivery, $delivery->getId());

        self::assertNotNull($receipt, 'the delivery is sealed as it happens - there is no later moment');
        self::assertSame(ReceiptOutcome::Delivered, $receipt->getOutcome());
        self::assertSame($document->getTitle(), $receipt->getDocumentTitle());

        // The sender needs the proof and the recipient needs the record.
        $readable = static::getContainer()->get(DeliveryReceiptRepository::class)->findReadableBy($sender);
        self::assertContains($receipt, $readable);
        self::assertContains($receipt, static::getContainer()->get(DeliveryReceiptRepository::class)->findReadableBy($recipient));
    }

    public function testOneIneligibleRecipientBlocksTheWholeDelivery(): void
    {
        [$sender, $ok] = $this->threeUsers();
        $unverified = $this->createUser($this->uniqueEmail('unverified'), verified: false);
        $document = $this->upload($sender);

        try {
            $this->service()->deliver($document, $sender, [$ok, $unverified]);
            self::fail('an unverified recipient should block the delivery');
        } catch (DomainException $e) {
            self::assertStringContainsString('has not verified', $e->getMessage());
        }

        // All or nothing: the eligible recipient was not served either.
        $grants = static::getContainer()->get(DocumentKeyGrantRepository::class);
        self::assertFalse($grants->hasGrantForDocument($document, $ok));
    }

    public function testOnlyTheOwnerCanDeliver(): void
    {
        [$sender, $other, $third] = $this->threeUsers();
        $document = $this->upload($sender);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only the owner');

        $this->service()->deliver($document, $other, [$third]);
    }

    public function testADocumentCanBeDeliveredRepeatedlyAndAlongsideASignatureRequest(): void
    {
        [$sender, $first, $second] = $this->threeUsers();
        $document = $this->upload($sender);

        // A delivery is not a request: it does not consume the one-request rule,
        // and it can happen again afterwards.
        $this->service()->deliver($document, $sender, [$first]);
        $this->giveCertificate($second);
        static::getContainer()->get(SigningRequestService::class)
            ->create($document, $sender, [$second], (new \DateTimeImmutable())->modify('+7 days'));
        $this->service()->deliver($document, $sender, [$second]);

        $deliveries = static::getContainer()->get(DeliveryRepository::class)->findForDocument($document);
        self::assertCount(2, $deliveries);
        self::assertTrue(static::getContainer()->get(DeliveryRepository::class)->wasServed($document, $first));
    }

    /** @return array{User, User, User} */
    private function threeUsers(): array
    {
        $users = [];
        foreach (['sender', 'first', 'second'] as $role) {
            $users[] = $this->createUser($this->uniqueEmail('delivery-'.$role), verified: true);
        }

        return [$users[0], $users[1], $users[2]];
    }

    private function upload(User $owner): Document
    {
        return static::getContainer()->get(DocumentUploader::class)
            ->upload($owner, self::MINIMAL_PDF, 'Notice of assignment.pdf');
    }

    private function giveCertificate(User $user): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $em->persist(new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Delivery Signer',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'delivery-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash('135790', \PASSWORD_ARGON2ID),
        ));
        $em->flush();
    }

    private function service(): DeliveryService
    {
        return static::getContainer()->get(DeliveryService::class);
    }
}
