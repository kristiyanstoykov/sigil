<?php

declare(strict_types=1);

namespace App\Account\EventSubscriber;

use App\Account\Event\DataExportFinished;
use App\Account\Service\DataExportNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Mail listeners swallow and log (ADR-013): the export is built either way. */
#[AsEventListener(event: DataExportFinished::class, priority: 0)]
final class SendDataExportMail
{
    public function __construct(
        private readonly DataExportNotifier $notifier,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DataExportFinished $event): void
    {
        try {
            $this->notifier->notifyFinished($event->export);
        } catch (\Throwable $e) {
            $this->logger->error('Could not send the data export mail.', [
                'exportId' => $event->export->getId()->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
