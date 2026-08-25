<?php

declare(strict_types=1);

namespace App\Delivery\EventSubscriber;

use App\Delivery\Event\DocumentDelivered;
use App\Delivery\Service\DeliveryNotifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Mail for a delivery: everyone served hears about it, in the same instant they
 * were served. Below Receipt's sealer, so the proof exists before anyone is told.
 */
#[AsEventListener(event: DocumentDelivered::class, priority: 0)]
final class SendDeliveryMail
{
    public function __construct(
        private readonly DeliveryNotifier $notifier,
    ) {
    }

    public function __invoke(DocumentDelivered $event): void
    {
        foreach ($event->delivery->getRecipients() as $recipient) {
            $this->notifier->notifyServed($event->delivery, $recipient->getUser());
        }
    }
}
