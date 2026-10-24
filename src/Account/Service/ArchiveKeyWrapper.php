<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Core\Crypto\Exception\DecryptionFailedException;
use App\Core\Crypto\Pkcs11WrappingKey;
use App\Core\Exception\DomainException;
use App\Core\Process\JsonDriver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Wraps a deleted user's KEK under the archive key (ADR-015 §2): a
 * non-exportable AES-256 key in its own `sigil-archive` token. Only deletion,
 * restore and the retention sweep use it, and it is deliberately not a root
 * wrapper scheme, so no day-to-day path can open an archived KEK.
 */
final class ArchiveKeyWrapper
{
    /** Scheme marker for archive-wrapped KEKs (root: env 0x01, token 0x02). */
    private const string SCHEME_BYTE = "\x03";

    private readonly Pkcs11WrappingKey $key;

    public function __construct(
        #[Autowire(env: 'PKCS11_MODULE')]
        string $modulePath,
        #[Autowire(env: 'SIGIL_ARCHIVE_TOKEN_LABEL')]
        private readonly string $tokenLabel,
        #[Autowire(env: 'SIGIL_ARCHIVE_KEY_LABEL')]
        string $keyLabel,
        #[Autowire(env: 'SIGIL_ARCHIVE_PIN')]
        #[\SensitiveParameter]
        string $pin,
        JsonDriver $driver,
    ) {
        $this->key = new Pkcs11WrappingKey($modulePath, $tokenLabel, $keyLabel, $pin, $driver, 'archive-key');
    }

    public function tokenLabel(): string
    {
        return $this->tokenLabel;
    }

    /** @param string $aad the owner's id, required identically to unwrap */
    public function wrapKek(#[\SensitiveParameter] string $rawKek, string $aad): string
    {
        return self::SCHEME_BYTE.$this->key->wrap($rawKek, $aad);
    }

    /** @throws DecryptionFailedException on tampering, the wrong owner or a foreign scheme */
    public function unwrapKek(string $wrappedKek, string $aad): string
    {
        if ('' === $wrappedKek || self::SCHEME_BYTE !== $wrappedKek[0]) {
            throw new DecryptionFailedException();
        }

        try {
            return $this->key->unwrap(substr($wrappedKek, 1), $aad);
        } catch (DomainException) {
            throw new DecryptionFailedException();
        }
    }

    /** Creates the archive key in the token if absent; true if it was created. */
    public function provisionKey(): bool
    {
        return $this->key->provision();
    }
}
