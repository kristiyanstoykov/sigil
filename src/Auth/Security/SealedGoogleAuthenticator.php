<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Core\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Google\GoogleAuthenticatorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Opens the sealed seed for the bundle at the one moment it is needed - code
 * check and QR provisioning - by handing the inner authenticator a view of the
 * user whose secret is the plaintext. The entity itself keeps returning the
 * envelope, so nothing that serialises or logs a User ever holds the seed.
 * Also the replay guard: a code that was accepted is not accepted again.
 */
#[AsDecorator('scheb_two_factor.security.google_authenticator')]
final class SealedGoogleAuthenticator implements GoogleAuthenticatorInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly GoogleAuthenticatorInterface $inner,
        private readonly TotpSecretVault $vault,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function checkCode(TwoFactorInterface $user, string $code): bool
    {
        $code = str_replace(' ', '', $code);
        $now = $this->clock->now();

        // RFC 6238 §5.2: a code is accepted once. The bundle has no hook for
        // this; the decorator is the seam, and the user row is the memory.
        if ($user instanceof User && $user->wasTotpCodeUsed($code, $now)) {
            return false;
        }

        if (!$this->inner->checkCode($this->unsealed($user), $code)) {
            return false;
        }

        if ($user instanceof User) {
            $user->recordTotpCode($code, $now);
            $this->em->flush();
        }

        return true;
    }

    public function getQRContent(TwoFactorInterface $user): string
    {
        return $this->inner->getQRContent($this->unsealed($user));
    }

    public function generateSecret(): string
    {
        return $this->inner->generateSecret();
    }

    private function unsealed(TwoFactorInterface $user): TwoFactorInterface
    {
        if (!$user instanceof User) {
            return $user;
        }

        $stored = $user->getGoogleAuthenticatorSecret();

        return new UnsealedTotpUser($user, null === $stored ? null : $this->vault->open($stored, $user->getId()->toRfc4122()));
    }
}
