<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Crypto\RootKeyProvider;

/**
 * Computes DocumentVersion.contentHash as a KEYED digest (HMAC-SHA-384 under a
 * root-derived MAC key), not a bare SHA-384 of the plaintext, and stores it
 * self-describing: "HMAC-SHA384/v1:<hex>" ({@see Fingerprint}).
 *
 * Rationale (2026-07-11 security review): under ADR-004's threat model (DB dump
 * + object-store leak) a plain plaintext hash would be a confirmation oracle -
 * an attacker could hash candidate documents and match. A keyed MAC leaks
 * nothing without the root key, while still giving a stable per-version
 * integrity fingerprint that Sigil itself can verify ({@see verify()}).
 */
final class ContentHasher
{
    public const string ALGORITHM = 'HMAC-SHA384/v1';

    private const MAC_CONTEXT = 'sigil:document-content-hash/v1';

    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly RootKeyProvider $rootKeys,
    ) {
    }

    public function hash(string $plaintext): string
    {
        return Fingerprint::of(self::ALGORITHM, $this->digest($plaintext))->toString();
    }

    /**
     * Whether $plaintext is the bytes $stored was computed over. Routes on the
     * stored algorithm id; an id this build does not know verifies as false.
     */
    public function verify(string $plaintext, string $stored): bool
    {
        $fingerprint = Fingerprint::fromString($stored);

        return match ($fingerprint->algorithm) {
            self::ALGORITHM => hash_equals($fingerprint->digest, $this->digest($plaintext)),
            default => false,
        };
    }

    private function digest(string $plaintext): string
    {
        $macKey = $this->encryption->deriveKey($this->rootKeys->rootKey(), self::MAC_CONTEXT);
        try {
            return bin2hex($this->encryption->mac($plaintext, $macKey));
        } finally {
            sodium_memzero($macKey);
        }
    }
}
