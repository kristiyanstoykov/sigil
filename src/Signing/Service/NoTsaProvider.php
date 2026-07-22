<?php

declare(strict_types=1);

namespace App\Signing\Service;

/**
 * The "no timestamp" backend - signatures degrade to PAdES-B-B. Selectable via
 * SIGIL_TSA_ACTIVE_BACKEND=none (e.g. offline dev, or when no TSA is reachable).
 */
final class NoTsaProvider implements TsaProviderInterface
{
    public function id(): string
    {
        return 'none';
    }

    public function url(): ?string
    {
        return null;
    }
}
