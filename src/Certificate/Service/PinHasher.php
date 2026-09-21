<?php

declare(strict_types=1);

namespace App\Certificate\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one place a certificate PIN is hashed or checked (ADR-008): Argon2id
 * with explicit costs, so a cost change is one parameter and an old hash is
 * upgraded on the next successful verify rather than left behind.
 *
 * Verifies against whatever algorithm the stored hash names, so the costs
 * (and even the algorithm) can move without invalidating existing PINs.
 */
final class PinHasher
{
    /**
     * PHP's own defaults for PASSWORD_ARGON2ID (64 MiB, 4 passes, 1 thread),
     * stated rather than inherited so a change is deliberate.
     */
    public function __construct(
        #[Autowire('%app.pin_hash.memory_cost%')]
        private readonly int $memoryCost = 65536,
        #[Autowire('%app.pin_hash.time_cost%')]
        private readonly int $timeCost = 4,
        #[Autowire('%app.pin_hash.threads%')]
        private readonly int $threads = 1,
    ) {
    }

    public function hash(#[\SensitiveParameter] string $pin): string
    {
        return password_hash($pin, \PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(#[\SensitiveParameter] string $pin, string $hash): bool
    {
        return password_verify($pin, $hash);
    }

    /** True when the hash was made with other costs or another algorithm. */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, \PASSWORD_ARGON2ID, $this->options());
    }

    /** @return array{memory_cost: int, time_cost: int, threads: int} */
    private function options(): array
    {
        return ['memory_cost' => $this->memoryCost, 'time_cost' => $this->timeCost, 'threads' => $this->threads];
    }
}
