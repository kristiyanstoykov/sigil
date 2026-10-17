<?php

declare(strict_types=1);

namespace App\Account\Event;

use App\Account\Entity\DataExport;

/** Dispatched once an export is ready or has failed; mail and inbox listen. */
final readonly class DataExportFinished
{
    public function __construct(public DataExport $export) {}
}
