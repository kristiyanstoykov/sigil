<?php

declare(strict_types=1);

namespace App\Notification\EventSubscriber;

use App\Account\Enum\DataExportStatus;
use App\Account\Event\DataExportFinished;
use App\Notification\Enum\NotificationType;
use App\Notification\Service\Notifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Inbox line for a finished data export. Swallows failures, like every notification listener. */
#[AsEventListener(event: DataExportFinished::class, priority: 0)]
final class NotifyOnDataExport
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DataExportFinished $event): void
    {
        $export = $event->export;
        $ready = DataExportStatus::Ready === $export->getStatus();

        try {
            $this->notifier->notify(
                recipient: $export->getOwner(),
                type: $ready ? NotificationType::DataExportReady : NotificationType::DataExportFailed,
                title: $ready ? 'Your export is ready to download' : 'Your export could not be prepared',
                url: $this->urls->generate('app_account_export', [], UrlGeneratorInterface::ABSOLUTE_URL),
                body: $ready
                    ? 'Each archive downloads once, and everything is deleted 24 hours after it was prepared.'
                    : 'Nothing was kept. Please request a new export.',
            );
        } catch (\Throwable $e) {
            $this->logger->error('Could not store a data export notification.', [
                'exportId' => $export->getId()->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
