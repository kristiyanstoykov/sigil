<?php

declare(strict_types=1);

namespace App\AuditLog\Service;

use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Repository\AuditLogEntryRepository;

/**
 * Walks the chain from genesis and recomputes every link - the one check the
 * console command and the audit page share, so they can never disagree.
 * Stops at the first broken entry: everything after it is unverifiable anyway.
 */
final class AuditChainVerifier
{
    public function __construct(private readonly AuditLogEntryRepository $repository)
    {
    }

    public function verify(): ChainVerification
    {
        $expectedPrevious = AuditLogEntry::GENESIS_HASH;
        $expectedSequence = 1;
        $count = 0;

        foreach ($this->repository->iterateChain() as $entry) {
            $reasons = [];
            if ($entry->getSequence() !== $expectedSequence) {
                $reasons[] = sprintf('sequence gap: expected %d, found %d', $expectedSequence, $entry->getSequence());
            }
            if ($entry->getPreviousHash() !== $expectedPrevious) {
                $reasons[] = 'previousHash does not match the preceding entry';
            }
            $recomputed = AuditChainHasher::hash($entry->getHashScheme(), $entry->getPreviousHash(), $entry->canonicalPayload());
            if (!hash_equals($recomputed, $entry->getEntryHash())) {
                $reasons[] = 'entryHash mismatch - entry content was modified';
            }
            if ([] !== $reasons) {
                return new ChainVerification($count, $entry->getSequence(), $entry->getAction(), $reasons);
            }

            $expectedPrevious = $entry->getEntryHash();
            $expectedSequence = $entry->getSequence() + 1;
            ++$count;
        }

        return new ChainVerification($count);
    }
}
