<?php

declare(strict_types=1);

namespace App\Delivery\Service;

use App\Core\Entity\User;

/**
 * Who may be served. Deliberately a lower bar than
 * {@see \App\Signing\Service\SignerEligibility}: a recipient is not asked to
 * sign, so no certificate is involved. A verified account is the whole test -
 * it is what makes the address a person Sigil can attest delivery to.
 */
final readonly class RecipientEligibility
{
    public function isEligible(User $user): bool
    {
        return null === $this->reasonWhyNot($user);
    }

    public function reasonWhyNot(User $user): ?string
    {
        if (!$user->isVerified()) {
            return sprintf('%s has not verified their email address yet.', $user->getEmail());
        }

        return null;
    }
}
