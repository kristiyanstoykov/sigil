<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Crypto\RootKeyProvider;

/**
 * Computes DocumentVersion.contentHash as a KEYED digest (HMAC-SHA-384 under a
 * root-derived MAC key), not a bare SHA-384 of the plaintext.
 *
 * Rationale (2026-07-11 security review): under ADR-004's threat model (DB dump
 * + object-store leak) a plain plaintext hash would be a confirmation oracle -
 * an attacker could hash candidate documents and match. A keyed MAC leaks
 * nothing without the root key, while still giving a stable per-version
 * integrity fingerprint. Output is 96 hex chars (48 bytes), matching the column.
 */
final class ContentHasher
{
    private const MAC_CONTEXT = 'sigil:document-content-hash/v1';

    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly RootKeyProvider $rootKeys,
    ) {
    }

    public function hash(string $plaintext): string
    {
        $macKey = $this->encryption->deriveKey($this->rootKeys->rootKey(), self::MAC_CONTEXT);
        try {
            return bin2hex($this->encryption->mac($plaintext, $macKey));
        } finally {
            sodium_memzero($macKey);
        }
    }
}
