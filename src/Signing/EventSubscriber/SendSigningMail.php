<?php

declare(strict_types=1);

namespace App\Signing\EventSubscriber;

use App\Signing\Enum\SigningRequestStatus;
use App\Signing\Event\DocumentSigned;
use App\Signing\Event\SigningRequestClosed;
use App\Signing\Event\SigningTurnReached;
use App\Signing\Service\SigningRequestNotifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Mail for the signing queue. The producers dispatch and know nothing about who
 * listens; this is one of two consumers, Notification being the other.
 *
 * Priority is below Receipt's sealer on purpose: a receipt must exist before
 * anything announces the request is over.
 */
final class SendSigningMail
{
    public function __construct(
        private readonly SigningRequestNotifier $notifier,
    ) {
    }

    #[AsEventListener(event: SigningTurnReached::class, priority: 0)]
    public function onTurnReached(SigningTurnReached $event): void
    {
        $this->notifier->notifyTurn($event->request, $event->signer);
    }

    #[AsEventListener(event: DocumentSigned::class, priority: 0)]
    public function onDocumentSigned(DocumentSigned $event): void
    {
        $this->notifier->notifySigned($event->document, $event->signer, $event->remaining);
    }

    #[AsEventListener(event: SigningRequestClosed::class, priority: 0)]
    public function onRequestClosed(SigningRequestClosed $event): void
    {
        $status = $event->request->getStatus();

        if (SigningRequestStatus::Completed === $status) {
            $this->notifier->notifyCompleted($event->request);

            return;
        }

        $this->notifier->notifyClosed($event->request, $status);
    }
}
