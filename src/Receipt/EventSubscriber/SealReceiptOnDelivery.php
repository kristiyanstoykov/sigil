<?php

declare(strict_types=1);

namespace App\Receipt\EventSubscriber;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Delivery\Event\DocumentDelivered;
use App\Receipt\Service\ReceiptGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Seals the receipt for a delivery - the same seam as
 * {@see SealReceiptOnRequestClosed}, so Delivery never has to know Receipt exists.
 *
 * Synchronous, and for a stronger reason than the request side: a delivery has no
 * later moment. It is finished when it is made, so the proof is produced then or
 * not at all.
 *
 * Failure to seal never fails the delivery. The document has already reached the
 * recipients and the audit chain already records it; the receipt is a rendering
 * of evidence that is safe to re-produce later.
 */
#[AsEventListener(event: DocumentDelivered::class)]
final class SealReceiptOnDelivery
{
    public function __construct(
        private readonly ReceiptGenerator $generator,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DocumentDelivered $event): void
    {
        try {
            $this->generator->generateForDelivery($event->delivery);
        } catch (\Throwable $e) {
            $this->logger->error('Could not seal the delivery receipt.', [
                'deliveryId' => $event->delivery->getId()->toRfc4122(),
                'exception' => $e,
            ]);

            $this->auditLogger->log(
                action: 'receipt.seal_failed',
                actor: $event->delivery->getSender(),
                payload: [
                    'deliveryId' => $event->delivery->getId()->toRfc4122(),
                    'error' => $e->getMessage(),
                ],
                subjectType: 'Document',
                subjectId: $event->delivery->getDocument()->getId()->toRfc4122(),
                severity: AuditSeverity::Warning,
            );
        }
    }
}
