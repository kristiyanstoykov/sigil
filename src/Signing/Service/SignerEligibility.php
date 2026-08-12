<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\Certificate\Repository\CertificateRepository;
use App\Core\Entity\User;
use Psr\Clock\ClockInterface;

/**
 * Who may be put on a signature request: a verified Sigil account holding a
 * usable certificate, the same predicate the sign page filters on.
 *
 * Checked when the request is sent and nowhere else. A certificate that lapses
 * while the request is pending is not re-validated - the signer renews and signs,
 * or the request expires on its deadline.
 */
final class SignerEligibility
{
    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly ClockInterface $clock,
    ) {
    }

    public function isEligible(User $user): bool
    {
        return null === $this->reasonWhyNot($user);
    }

    /** Null when the user may sign, otherwise a sentence naming what is missing. */
    public function reasonWhyNot(User $user): ?string
    {
        if (!$user->isVerified()) {
            return sprintf('%s has not verified their email address yet.', $user->getEmail());
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        foreach ($this->certificates->findByUser($user) as $certificate) {
            if ($certificate->isUsable($now)) {
                return null;
            }
        }

        return sprintf('%s has no usable certificate, so they cannot sign.', $user->getEmail());
    }
}
