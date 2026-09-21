<?php

declare(strict_types=1);

namespace App\Core\Process;

/**
 * A bin/ driver said no. $error is the driver's type-only error string (an
 * exception class name or a stable code such as "EncryptedPdf") - never a
 * message, which could echo input - so it is safe to audit and to map.
 */
final class DriverException extends \RuntimeException
{
    public function __construct(
        public readonly string $script,
        public readonly string $error,
    ) {
        parent::__construct(sprintf('%s failed (%s).', $script, $error));
    }
}
