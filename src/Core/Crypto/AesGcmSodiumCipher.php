<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use App\Core\Crypto\Exception\DecryptionFailedException;
use App\Core\Exception\DomainException;

/**
 * AES-256-GCM via libsodium's hardware-accelerated primitive. Requires AES-NI;
 * we fail closed (throw at construction) if it is unavailable rather than
 * silently falling back to a software path - the deployment must have it.
 *
 * The default and, for the MVP, only symmetric suite (ADR-006). All-symmetric,
 * so quantum-resistant (no Shor exposure).
 */
final class AesGcmSodiumCipher implements CipherAlgorithmInterface
{
    public const ID = 'AES-256-GCM/v1';

    public function __construct()
    {
        if (!sodium_crypto_aead_aes256gcm_is_available()) {
            throw new DomainException(
                'AES-256-GCM is unavailable on this platform (AES-NI required). Refusing to start.',
            );
        }
    }

    public function id(): string
    {
        return self::ID;
    }

    public function keyLength(): int
    {
        return SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES; // 32
    }

    public function nonceLength(): int
    {
        return SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES; // 12
    }

    public function encrypt(string $plaintext, string $nonce, string $key, string $aad): string
    {
        $this->assertLengths($nonce, $key);

        return sodium_crypto_aead_aes256gcm_encrypt($plaintext, $aad, $nonce, $key);
    }

    public function decrypt(string $ciphertext, string $nonce, string $key, string $aad): string
    {
        $this->assertLengths($nonce, $key);

        $plaintext = sodium_crypto_aead_aes256gcm_decrypt($ciphertext, $aad, $nonce, $key);
        if (false === $plaintext) {
            throw new DecryptionFailedException();
        }

        return $plaintext;
    }

    private function assertLengths(string $nonce, string $key): void
    {
        if (self::keyBytes() !== \strlen($key)) {
            throw new DomainException('AES-256-GCM key must be exactly 32 bytes.');
        }
        if (self::nonceBytes() !== \strlen($nonce)) {
            throw new DomainException('AES-256-GCM nonce must be exactly 12 bytes.');
        }
    }

    private static function keyBytes(): int
    {
        return SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES;
    }

    private static function nonceBytes(): int
    {
        return SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES;
    }
}
