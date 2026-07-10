<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One implementation per supported symmetric AEAD suite (ADR-006). The suite
 * is identified by a stable, versioned id that is written into every envelope
 * and bound into the AEAD associated data, so ciphertext is self-describing
 * and old artifacts stay decryptable when the default changes.
 *
 * Adding a suite = adding a class; nothing else changes. Never reuse or
 * repurpose an id.
 *
 * This is the low-level primitive. Business code must NOT use it directly -
 * it goes through {@see \App\Core\Crypto\EncryptionServiceInterface}, which
 * owns the envelope framing and algo-id binding.
 */
#[AutoconfigureTag('app.cipher_algorithm')]
interface CipherAlgorithmInterface
{
    /** Stable, versioned identifier written into the envelope, e.g. "AES-256-GCM/v1". */
    public function id(): string;

    /** Required key length in bytes (32 for AES-256). */
    public function keyLength(): int;

    /** Nonce length in bytes (12 for GCM's 96-bit nonce). */
    public function nonceLength(): int;

    /**
     * AEAD-encrypt. Returns ciphertext with the authentication tag appended.
     *
     * @param string $plaintext raw bytes to encrypt
     * @param string $nonce     exactly {@see nonceLength()} bytes, never reused with the same key
     * @param string $key       exactly {@see keyLength()} bytes
     * @param string $aad       additional authenticated data (bound, not encrypted)
     */
    public function encrypt(string $plaintext, string $nonce, string $key, string $aad): string;

    /**
     * AEAD-decrypt and verify. Throws on any authentication failure.
     *
     * @param string $ciphertext ciphertext with the tag appended (as produced by encrypt)
     *
     * @throws \App\Core\Crypto\Exception\DecryptionFailedException
     */
    public function decrypt(string $ciphertext, string $nonce, string $key, string $aad): string;
}
