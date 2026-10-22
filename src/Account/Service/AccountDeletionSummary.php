<?php

declare(strict_types=1);

namespace App\Account\Service;

/**
 * What a deletion did, for the closure record and the confirmation page
 * (ADR-015 §3). The recovery code is here once, in memory, and never stored.
 */
final readonly class AccountDeletionSummary
{
    /**
     * @param array<string, string> $closedRequests    request id => how it closed
     * @param list<string>          $revokedSerials
     */
    public function __construct(
        public string $userId,
        public \DateTimeImmutable $deletedAt,
        public \DateTimeImmutable $retainUntil,
        public array $closedRequests,
        public array $revokedSerials,
        public int $draftsErased,
        public int $documentsRetained,
        #[\SensitiveParameter]
        public string $recoveryCode,
    ) {
    }
}
