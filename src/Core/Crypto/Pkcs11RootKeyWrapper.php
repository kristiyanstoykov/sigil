<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use App\Core\Crypto\Exception\DecryptionFailedException;
use App\Core\Exception\DomainException;
use App\Core\Process\JsonDriver;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Root-key wrapper backed by a PKCS#11 token (ADR-010). The root wrapping key
 * is a non-exportable AES-256 secret key that lives INSIDE the token
 * (kryoptic in dev, a real HSM/KMS in prod - same interface). It never enters
 * application memory; the app only ever asks the token to wrap/unwrap a single
 * KEK per call. An attacker with app-code execution can therefore only use the
 * token as an online oracle (auditable, rate-limitable) - they cannot exfiltrate
 * the root key and bulk-decrypt offline, which the env root key allowed.
 *
 * Wrap/unwrap runs in a short-lived Python driver (bin/wrap_key.py) over the
 * established PKCS#11 shell-out pattern (ADR-005). The token PIN and the raw KEK
 * cross only via the child's stdin/stdout pipes as base64 JSON - never argv
 * (world-readable in /proc), never disk.
 *
 * Storage framing: the token produces a raw `nonce || ciphertext‖tag` blob; this
 * class prepends a scheme byte (0x02) so a token-wrapped KEK is self-describing
 * and cannot be confused with an env-wrapped one ({@see EnvRootKeyWrapper}, 0x01).
 */
final class Pkcs11RootKeyWrapper implements RootKeyWrapperSchemeInterface
{
    public const string ID = 'pkcs11';

    public function id(): string
    {
        return self::ID;
    }

    public function schemeByte(): string
    {
        return self::SCHEME_BYTE;
    }

    /** Scheme marker for token-wrapped KEKs; distinguishes them from env 0x01 blobs. */
    private const SCHEME_BYTE = "\x02";

    private readonly Pkcs11WrappingKey $key;

    public function __construct(
        #[Autowire(env: 'PKCS11_MODULE')]
        string $modulePath,
        #[Autowire(env: 'SIGIL_ROOT_TOKEN_LABEL')]
        string $tokenLabel,
        #[Autowire(env: 'SIGIL_ROOT_KEY_LABEL')]
        string $keyLabel,
        #[Autowire(env: 'SIGIL_ROOT_TOKEN_PIN')]
        #[\SensitiveParameter]
        string $pin,
        JsonDriver $driver,
    ) {
        $this->key = new Pkcs11WrappingKey($modulePath, $tokenLabel, $keyLabel, $pin, $driver, 'root-key');
    }

    public function wrapKek(#[\SensitiveParameter] string $rawKek, string $aad): string
    {
        return self::SCHEME_BYTE.$this->key->wrap($rawKek, $aad);
    }

    public function unwrapKek(string $wrappedKek, string $aad): string
    {
        if ('' === $wrappedKek || self::SCHEME_BYTE !== $wrappedKek[0]) {
            // Not a token-wrapped blob (or tampered) - refuse rather than feed a
            // foreign scheme to the token.
            throw new DecryptionFailedException();
        }

        try {
            return $this->key->unwrap(substr($wrappedKek, 1), $aad);
        } catch (DomainException) {
            throw new DecryptionFailedException();
        }
    }

    /**
     * Create the non-exportable root wrapping key in the token if absent.
     * Idempotent - returns true if it created the key, false if it already existed.
     * Called by `sigil:root-key:init` after the token itself is provisioned.
     */
    public function provisionKey(): bool
    {
        return $this->key->provision();
    }
}
