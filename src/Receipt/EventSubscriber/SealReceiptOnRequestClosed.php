<?php

declare(strict_types=1);

namespace App\Receipt\EventSubscriber;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Receipt\Service\ReceiptGenerator;
use App\Signing\Event\SigningRequestClosed;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Seals the delivery receipt when a signature request closes - the seam that
 * lets Receipt depend on Signing without Signing knowing Receipt exists.
 *
 * Runs synchronously inside the closing request, which matters for the expiry
 * sweep: an unsigned request has its document erased right after it closes, so
 * the receipt has to be built while the document is still there.
 *
 * Failure to seal never fails the close. The request is already closed on the
 * record and the audit chain already holds the evidence; a missing receipt is a
 * rendering problem, and re-running generation later is safe.
 */
#[AsEventListener(event: SigningRequestClosed::class)]
final class SealReceiptOnRequestClosed
{
    public function __construct(
        private readonly ReceiptGenerator $generator,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SigningRequestClosed $event): void
    {
        try {
            $this->generator->generateFor($event->request);
        } catch (\Throwable $e) {
            $this->logger->error('Could not seal the delivery receipt.', [
                'requestId' => $event->request->getId()->toRfc4122(),
                'exception' => $e,
            ]);

            $this->auditLogger->log(
                action: 'receipt.seal_failed',
                actor: $event->request->getRequester(),
                payload: [
                    'requestId' => $event->request->getId()->toRfc4122(),
                    'error' => $e->getMessage(),
                ],
                subjectType: 'Document',
                subjectId: $event->request->getDocument()->getId()->toRfc4122(),
                severity: AuditSeverity::Warning,
            );
        }
    }
}
