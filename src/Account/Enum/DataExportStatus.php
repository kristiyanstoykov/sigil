<?php

declare(strict_types=1);

namespace App\Account\Enum;

/** Where a data export is (ADR-015 §7). Once ready, its files belong to TempDownload. */
enum DataExportStatus: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isInProgress(): bool
    {
        return self::Queued === $this || self::Building === $this;
    }
}
