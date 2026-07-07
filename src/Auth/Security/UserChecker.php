<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Core\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Runs during authentication, before credentials are checked. This is the
 * correct place to reject an account that exists but is not allowed to log in
 * yet - here, an unverified email address.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            throw new UnverifiedEmailException();
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // no post-authentication checks
    }
}
