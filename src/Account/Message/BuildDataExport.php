<?php

declare(strict_types=1);

namespace App\Account\Message;

/**
 * Carries the export id and nothing else: the worker unwraps the owner's KEK
 * through the root wrapper, so no secret ever enters the queue.
 */
final readonly class BuildDataExport
{
    public function __construct(public string $exportId) {}
}
