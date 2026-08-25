<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Certificate\Entity\Certificate;
use App\Core\Entity\User;
use App\Delivery\Service\DeliveryService;
use App\Document\Entity\Document;
use App\Document\Service\DocumentUploader;
use App\Notification\Enum\NotificationType;
use App\Notification\Repository\NotificationRepository;
use App\Signing\Service\SigningRequestService;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The in-app inbox. Every row here is written by a subscriber on a domain event,
 * so these tests are also what proves the producers dispatch at all.
 */
class InAppNotificationTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testADeliveryReachesEveryRecipientInboxAndNotTheSenders(): void
    {
        $sender = $this->user('sender');
        $first = $this->user('first');
        $second = $this->user('second');
        $document = $this->upload($sender);

        static::getContainer()->get(DeliveryService::class)
            ->deliver($document, $sender, [$first, $second], 'For your records.');

        foreach ([$first, $second] as $recipient) {
            $rows = $this->inbox()->findRecentFor($recipient);
            self::assertCount(1, $rows);
            self::assertSame(NotificationType::DocumentDelivered, $rows[0]->getType());
            self::assertStringContainsString($document->getTitle(), $rows[0]->getTitle());
            self::assertStringContainsString('For your records.', (string) $rows[0]->getBody());
            self::assertFalse($rows[0]->isRead());
            self::assertSame(1, $this->inbox()->countUnreadFor($recipient));
        }

        // The sender served it; nobody needs telling they did what they just did.
        self::assertSame([], $this->inbox()->findRecentFor($sender));
    }

    /**
     * Access is granted per turn and so is the notification: a signer sitting
     * behind someone else has nothing to do yet, and being told otherwise would
     * contradict the sign page they would land on.
     */
    public function testOnlyTheSignerWhoseTurnItIsHearsAboutIt(): void
    {
        $owner = $this->user('owner');
        $first = $this->user('first-signer');
        $second = $this->user('second-signer');
        $this->giveCertificate($first);
        $this->giveCertificate($second);
        $document = $this->upload($owner);

        static::getContainer()->get(SigningRequestService::class)->create(
            $document,
            $owner,
            [$first, $second],
            (new \DateTimeImmutable())->modify('+7 days'),
        );

        $rows = $this->inbox()->findRecentFor($first);
        self::assertCount(1, $rows);
        self::assertSame(NotificationType::SignatureRequested, $rows[0]->getType());
        self::assertStringContainsString('signer 1 of 2', (string) $rows[0]->getBody());

        self::assertSame([], $this->inbox()->findRecentFor($second), 'an unreached signer has nothing to do yet');
        self::assertSame([], $this->inbox()->findRecentFor($owner));
    }

    /**
     * The ADR-012 boundary. readAt belongs to the recipient's inbox: reading a
     * notification records nothing about the document, so it is not a read
     * receipt and Sigil still attests consignment only.
     */
    public function testReadingANotificationRecordsNothingAboutTheDocument(): void
    {
        $sender = $this->user('sender');
        $recipient = $this->user('recipient');
        $document = $this->upload($sender);

        static::getContainer()->get(DeliveryService::class)->deliver($document, $sender, [$recipient]);

        $audit = static::getContainer()->get(AuditLogEntryRepository::class);
        $before = \count($audit->findForSubject('Document', $document->getId()->toRfc4122()));

        $notification = $this->inbox()->findRecentFor($recipient)[0];
        $notification->markRead(new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        self::assertTrue($notification->isRead());
        self::assertSame(
            $before,
            \count($audit->findForSubject('Document', $document->getId()->toRfc4122())),
            'reading an inbox row must not enter the evidence chain',
        );

        // And it is gone from the recipient's own to-do list, which is the whole
        // point: a delivery asks nothing, so nothing else could ever clear it.
        self::assertSame([], $this->inbox()->findUnreadFor($recipient, [NotificationType::DocumentDelivered]));
        self::assertSame(0, $this->inbox()->countUnreadFor($recipient));
    }

    public function testMarkAllReadClearsTheBadgeAndNothingElse(): void
    {
        $sender = $this->user('sender');
        $recipient = $this->user('recipient');

        foreach (['One.pdf', 'Two.pdf'] as $title) {
            $document = $this->upload($sender, $title);
            static::getContainer()->get(DeliveryService::class)->deliver($document, $sender, [$recipient]);
        }

        self::assertSame(2, $this->inbox()->countUnreadFor($recipient));

        $marked = $this->inbox()->markAllReadFor($recipient, new \DateTimeImmutable());

        self::assertSame(2, $marked);
        self::assertSame(0, $this->inbox()->countUnreadFor($recipient));
        self::assertCount(2, $this->inbox()->findRecentFor($recipient), 'read is not deleted');
    }

    private function inbox(): NotificationRepository
    {
        return static::getContainer()->get(NotificationRepository::class);
    }

    private function user(string $prefix): User
    {
        return $this->createUser($this->uniqueEmail('notify-'.$prefix), verified: true);
    }

    private function upload(User $owner, string $title = 'Notice of assignment.pdf'): Document
    {
        return static::getContainer()->get(DocumentUploader::class)
            ->upload($owner, self::MINIMAL_PDF, $title);
    }

    private function giveCertificate(User $user): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $em->persist(new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Notification Signer',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'notify-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash('135790', \PASSWORD_ARGON2ID),
        ));
        $em->flush();
    }
}
