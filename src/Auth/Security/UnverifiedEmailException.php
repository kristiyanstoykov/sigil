<?php

declare(strict_types=1);

namespace App\Auth\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

/**
 * Thrown by the UserChecker when a login is attempted on an account whose email
 * is not yet verified. A dedicated class (rather than the generic account-status
 * exception) lets the login-failure subscriber recognise this case and redirect
 * the user to the resend-verification page instead of the plain login error.
 */
final class UnverifiedEmailException extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('Please verify your email address before logging in.');
    }
}
