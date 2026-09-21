<?php

declare(strict_types=1);

namespace App\Certificate\Security;

use App\Certificate\Entity\Certificate;
use App\Core\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * A certificate is its holder's alone: detail, PIN change, hold, resume,
 * revoke. Controllers answer a refusal with 404, so an id is never confirmed.
 *
 * @extends Voter<string, Certificate>
 */
final class CertificateVoter extends Voter
{
    public const string OWN = 'CERTIFICATE_OWN';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::OWN === $attribute && $subject instanceof Certificate;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $subject->getUser()->is($user);
    }
}
