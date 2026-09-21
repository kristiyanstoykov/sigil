<?php

declare(strict_types=1);

namespace App\Auth\Security;

use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface;

/** A user as the TOTP library needs to see it: the same identity, the seed in the clear. */
final readonly class UnsealedTotpUser implements TwoFactorInterface
{
    public function __construct(
        private TwoFactorInterface $user,
        #[\SensitiveParameter] private ?string $secret,
    ) {
    }

    public function isGoogleAuthenticatorEnabled(): bool
    {
        return $this->user->isGoogleAuthenticatorEnabled();
    }

    public function getGoogleAuthenticatorUsername(): ?string
    {
        return $this->user->getGoogleAuthenticatorUsername();
    }

    public function getGoogleAuthenticatorSecret(): ?string
    {
        return $this->secret;
    }
}
