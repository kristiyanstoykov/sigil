<?php

declare(strict_types=1);

namespace App\Document\Service;

/**
 * A stored content fingerprint, self-describing: "<algorithm>:<hex>", e.g.
 * "HMAC-SHA384/v1:9f…". The algorithm travels with the value so a future MAC
 * (or a different key derivation) can verify old rows by their own id rather
 * than by guessing - the same rule the encryption envelope follows (ADR-006).
 */
final readonly class Fingerprint
{
    private function __construct(
        public string $algorithm,
        public string $digest,
    ) {
    }

    public static function of(string $algorithm, string $digest): self
    {
        return new self($algorithm, $digest);
    }

    /** Parses a stored value; a legacy bare hex (pre-2026-09-21 rows) is HMAC-SHA384/v1 by construction. */
    public static function fromString(string $stored): self
    {
        $at = strrpos($stored, ':');
        if (false === $at) {
            return new self(ContentHasher::ALGORITHM, $stored);
        }

        return new self(substr($stored, 0, $at), substr($stored, $at + 1));
    }

    public function toString(): string
    {
        return $this->algorithm.':'.$this->digest;
    }
}
