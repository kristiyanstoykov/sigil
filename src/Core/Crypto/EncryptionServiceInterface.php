<?php

declare(strict_types=1);

namespace App\Core\Crypto;

/**
 * The single entry point for symmetric crypto in the whole application.
 * Business code MUST go through this - never call sodium_* / openssl_*
 * directly (security invariant).
 *
 * It produces a versioned, self-describing envelope
 * (format ‖ algo_id ‖ nonce ‖ ciphertext‖tag) with the algo_id bound into the
 * AEAD associated data, so the suite cannot be downgraded by flipping the id.
 *
 * The same primitive both encrypts file bytes and wraps keys - key wrapping is
 * just encrypting the raw key bytes as plaintext under the parent key. This is
 * what the three-layer envelope (ADR-004) is built on: root wraps KEK, KEK
 * wraps DEK, DEK encrypts the file.
 */
interface EncryptionServiceInterface
{
    /**
     * Encrypt plaintext under $key with the default suite, returning a complete
     * self-describing envelope.
     *
     * @param string $key raw key bytes matching the default suite's key length
     * @param string $aad optional caller context bound into the envelope (e.g. a
     *                     document-version id) - the exact same value is required to decrypt
     */
    public function encrypt(string $plaintext, string $key, string $aad = ''): string;

    /**
     * Decrypt an envelope produced by {@see encrypt()}. The suite is read from
     * the envelope itself (crypto agility).
     *
     * @throws Exception\DecryptionFailedException on any tampering, wrong key, or malformed input
     */
    public function decrypt(string $envelope, string $key, string $aad = ''): string;

    /** Fresh random key sized for the default suite (32 bytes). For KEKs and DEKs. */
    public function generateKey(): string;

    /**
     * Derive a 32-byte subkey from input key material for a fixed purpose (HKDF).
     * Lets one root key safely yield distinct, independent keys per use - e.g. a
     * MAC key that is separate from any wrapping key.
     */
    public function deriveKey(string $inputKeyMaterial, string $context): string;

    /**
     * Keyed MAC (HMAC-SHA-384) of $message under $key, raw bytes (48).
     * For integrity tags that must NOT be forgeable or recomputable without the
     * key - unlike a bare hash, this leaks nothing to someone holding only the DB.
     */
    public function mac(string $message, string $key): string;
}
