<?php

declare(strict_types=1);

namespace App\Account\Event;

use App\Account\Service\AccountDeletionSummary;
use App\Core\Entity\User;

/**
 * Dispatched after the deletion committed. Carries the former address, which
 * the tombstoned row no longer has, so the closure mail can still reach it.
 */
final readonly class AccountDeleted
{
    public function __construct(
        public User $user,
        public string $formerEmail,
        public string $formerName,
        public AccountDeletionSummary $summary,
    ) {
    }
}
