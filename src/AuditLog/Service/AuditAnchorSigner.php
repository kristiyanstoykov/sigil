<?php

declare(strict_types=1);

namespace App\AuditLog\Service;

use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Crypto\RootKeyProvider;
use Psr\Clock\ClockInterface;

/**
 * Mints and checks {@see AuditAnchor}s. The MAC key is derived from the root
 * key (ADR-010), so a party with database access but not the root key can
 * neither rewrite the tail nor forge a checkpoint that blesses the rewrite.
 * An attacker holding the root key too is the host compromise ADR-010 already
 * names - the anchor's copy outside the host is the remaining defence.
 */
final class AuditAnchorSigner
{
    public const string SCHEME = 'HMAC-SHA384/v1';

    private const MAC_CONTEXT = 'sigil:audit-anchor/v1';

    public function __construct(
        private readonly AuditLogEntryRepository $entries,
        private readonly EncryptionServiceInterface $encryption,
        private readonly RootKeyProvider $rootKeys,
        private readonly ClockInterface $clock,
    ) {
    }

    /** A checkpoint of the chain as it stands; null while the log is empty. */
    public function anchorHead(): ?AuditAnchor
    {
        $head = $this->entries->findChainHead();
        if (null === $head) {
            return null;
        }

        $unsigned = new AuditAnchor($head->getSequence(), $head->getEntryHash(), $this->clock->now(), self::SCHEME, '');

        return new AuditAnchor($unsigned->sequence, $unsigned->entryHash, $unsigned->anchoredAt, $unsigned->scheme, $this->mac($unsigned));
    }

    /** Whether the anchor was minted here and is untouched. */
    public function isAuthentic(AuditAnchor $anchor): bool
    {
        return self::SCHEME === $anchor->scheme && hash_equals($this->mac($anchor), $anchor->mac);
    }

    /**
     * Whether the chain still contains what the anchor attested: the entry at
     * that sequence with that hash. A shorter chain (tail deleted) or a
     * different hash there (history rewritten) fails.
     */
    public function stillHolds(AuditAnchor $anchor): bool
    {
        $entry = $this->entries->findOneBy(['sequence' => (string) $anchor->sequence]);

        return null !== $entry && hash_equals($anchor->entryHash, $entry->getEntryHash());
    }

    private function mac(AuditAnchor $anchor): string
    {
        $key = $this->encryption->deriveKey($this->rootKeys->rootKey(), self::MAC_CONTEXT);
        try {
            return bin2hex($this->encryption->mac($anchor->signedBytes(), $key));
        } finally {
            sodium_memzero($key);
        }
    }
}
