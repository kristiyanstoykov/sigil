<?php

declare(strict_types=1);

namespace App\Certificate\Service;

use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Crypto\RootKeyProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The one place a certificate PIN is hashed or checked (ADR-008).
 *
 * A PIN is 6-8 digits: a 10^6-10^8 space that Argon2id alone only slows down,
 * so a leaked `certificate` table would yield every PIN in about a day per
 * core. The PIN is therefore *peppered* first - HMAC-SHA-384 under a key
 * derived from SIGIL_ROOT_KEY - and Argon2id runs over that. Without the
 * host's key the stored hash confirms nothing; with the table alone there is
 * nothing to brute-force. Peppered hashes carry a prefix; a bare Argon2id hash
 * from before 2026-09-21 still verifies and is re-hashed on the next good PIN.
 *
 * Costs are explicit, so a change is deliberate and an old hash is upgraded
 * on the next successful verify rather than left behind.
 */
final class PinHasher
{
    /** Marks a hash whose input was the peppered PIN; the number is the pepper scheme. */
    public const string PREFIX = 'pepper:v1$';

    private const PEPPER_CONTEXT = 'sigil:pin-pepper/v1';

    /**
     * PHP's own defaults for PASSWORD_ARGON2ID (64 MiB, 4 passes, 1 thread),
     * stated rather than inherited so a change is deliberate.
     */
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly RootKeyProvider $rootKeys,
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
        return self::PREFIX.password_hash($this->pepper($pin), \PASSWORD_ARGON2ID, $this->options());
    }

    public function verify(#[\SensitiveParameter] string $pin, string $hash): bool
    {
        if (str_starts_with($hash, self::PREFIX)) {
            return password_verify($this->pepper($pin), substr($hash, \strlen(self::PREFIX)));
        }

        // Legacy: Argon2id straight over the PIN. Verifies, and needsRehash() says so.
        return password_verify($pin, $hash);
    }

    /** True when the hash is unpeppered, or was made with other costs or another algorithm. */
    public function needsRehash(string $hash): bool
    {
        if (!str_starts_with($hash, self::PREFIX)) {
            return true;
        }

        return password_needs_rehash(substr($hash, \strlen(self::PREFIX)), \PASSWORD_ARGON2ID, $this->options());
    }

    private function pepper(#[\SensitiveParameter] string $pin): string
    {
        $key = $this->encryption->deriveKey($this->rootKeys->rootKey(), self::PEPPER_CONTEXT);
        try {
            return bin2hex($this->encryption->mac($pin, $key));
        } finally {
            sodium_memzero($key);
        }
    }

    /** @return array{memory_cost: int, time_cost: int, threads: int} */
    private function options(): array
    {
        return ['memory_cost' => $this->memoryCost, 'time_cost' => $this->timeCost, 'threads' => $this->threads];
    }
}
