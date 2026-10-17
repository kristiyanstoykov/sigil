<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\DataExport;
use App\Account\Enum\DataExportStatus;
use App\Mailer\Service\Mailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Outbound mail for data exports, best effort through Mailer::trySend. */
final class DataExportNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function notifyFinished(DataExport $export): void
    {
        $ready = DataExportStatus::Ready === $export->getStatus();

        $this->mailer->trySend(
            (new TemplatedEmail())
                ->to($export->getOwner()->getEmail())
                ->subject($ready ? 'Your Sigil export is ready' : 'Your Sigil export could not be prepared')
                ->htmlTemplate('emails/data_export_finished.html.twig')
                ->context([
                    'ready' => $ready,
                    'parts' => $export->getPartCount(),
                    'files' => $export->getFileCount(),
                    'url' => $this->urls->generate('app_account_export', [], UrlGeneratorInterface::ABSOLUTE_URL),
                ]),
        );
    }
}
