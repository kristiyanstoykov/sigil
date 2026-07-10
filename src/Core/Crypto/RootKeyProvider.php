<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use App\Core\Exception\DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Supplies the application root key - the top of the three-layer envelope
 * (ADR-004). It wraps every per-user KEK and lives ONLY in the environment
 * (`SIGIL_ROOT_KEY`, from env/KMS) - never in the database, never on disk.
 *
 * The value is base64-encoded 32 bytes. We validate and decode once, at
 * construction, and fail closed if it is absent or the wrong size - the app
 * must not run key management with a bad root key.
 */
final class RootKeyProvider
{
    private readonly string $rootKey;

    public function __construct(
        #[Autowire('%env(SIGIL_ROOT_KEY)%')]
        string $base64RootKey,
    ) {
        $raw = base64_decode($base64RootKey, true);
        if (false === $raw || 32 !== \strlen($raw)) {
            throw new DomainException(
                'SIGIL_ROOT_KEY must be a base64-encoded 32-byte key. '
                .'Generate one with: php -r "echo base64_encode(random_bytes(32));"',
            );
        }

        $this->rootKey = $raw;
    }

    /** Raw 32-byte root key. Exists only in memory; never persist or log it. */
    public function rootKey(): string
    {
        return $this->rootKey;
    }
}
