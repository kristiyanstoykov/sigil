<?php

declare(strict_types=1);

namespace App\Auth\Event;

use App\Core\Entity\User;

/**
 * Someone registered with an address that belongs to a deleted account
 * (ADR-015 §5). No account is created; whoever restores accounts answers it.
 */
final readonly class DeletedAccountRegistration
{
    public function __construct(
        public User $deletedUser,
        public string $email,
    ) {
    }
}
