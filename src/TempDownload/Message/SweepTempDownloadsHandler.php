<?php

declare(strict_types=1);

namespace App\TempDownload\Message;

use App\TempDownload\Service\TempDownloadService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SweepTempDownloadsHandler
{
    public function __construct(private TempDownloadService $downloads) {}

    public function __invoke(SweepTempDownloads $message): void
    {
        $this->downloads->sweep();
    }
}
