<?php

declare(strict_types=1);

namespace App\AuditLog\Service;

/** The outcome of walking the whole chain: intact through $entries, or broken at $brokenAt for $reasons. */
final readonly class ChainVerification
{
    /** @param list<string> $reasons */
    public function __construct(
        public int $entries,
        public ?int $brokenAt = null,
        public ?string $brokenAction = null,
        public array $reasons = [],
    ) {
    }

    public function isIntact(): bool
    {
        return null === $this->brokenAt;
    }
}
