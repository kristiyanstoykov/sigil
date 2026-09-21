<?php

declare(strict_types=1);

namespace App\Signing\Security;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Signing\Repository\SigningRequestRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may open the sign page for a document: its owner, or anyone listed on
 * its pending request - whose turn it is decides what they see there, not
 * whether they get in.
 *
 * @extends Voter<string, Document>
 */
final class SigningVoter extends Voter
{
    public const string SIGN = 'DOCUMENT_SIGN';

    public function __construct(private readonly SigningRequestRepository $requests)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::SIGN === $attribute && $subject instanceof Document;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($subject->getOwner()->is($user)) {
            return true;
        }

        $request = $this->requests->findPendingForDocument($subject);

        return null !== $request && null !== $request->signerFor($user);
    }
}
