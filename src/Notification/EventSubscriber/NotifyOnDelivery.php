<?php

declare(strict_types=1);

namespace App\Notification\EventSubscriber;

use App\Delivery\Event\DocumentDelivered;
use App\Notification\Enum\NotificationType;
use App\Notification\Service\Notifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * In-app notification for a delivery: everyone served gets one, in the instant
 * they were served.
 *
 * The row is what makes the delivery visible in the account, alongside the key
 * grant that makes it readable. Whether it is later marked read is an inbox
 * detail belonging to the recipient - it is not a read receipt, is never shown
 * to the sender, and does not enter the audit log or the sealed receipt, so
 * ADR-012's consignment-not-retrieval rule is untouched.
 *
 * Failures are swallowed: a delivery has already been made and attested by the
 * time this runs, and cannot be undone by an inbox row failing to write.
 */
#[AsEventListener(event: DocumentDelivered::class, priority: 0)]
final class NotifyOnDelivery
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DocumentDelivered $event): void
    {
        $delivery = $event->delivery;
        $document = $delivery->getDocument();
        $url = $this->urls->generate(
            'app_document_show',
            ['id' => $document->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        foreach ($delivery->getRecipients() as $recipient) {
            try {
                $this->notifier->notify(
                    recipient: $recipient->getUser(),
                    type: NotificationType::DocumentDelivered,
                    title: sprintf('Delivered to you: %s', $document->getTitle()),
                    url: $url,
                    body: sprintf(
                        '%s served this document on you.%s',
                        $delivery->getSender()->getFullName(),
                        null !== $delivery->getNote() ? ' Note: '.$delivery->getNote() : '',
                    ),
                    documentId: $document->getId(),
                );
            } catch (\Throwable $e) {
                $this->logger->error('Could not store a delivery notification.', [
                    'deliveryId' => $delivery->getId()->toRfc4122(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
