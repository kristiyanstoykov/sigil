<?php

declare(strict_types=1);

namespace App\Document\Security;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentKeyGrantRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may do what to a document, in one place. VIEW is the ADR-004 rule -
 * the grants are the access list, and the owner always holds one - and OWN is
 * everything that changes a document's life (send, deliver, withdraw).
 *
 * Controllers answer a refusal with 404, not 403: an id must not be confirmed
 * to someone who cannot see the row.
 *
 * @extends Voter<string, Document>
 */
final class DocumentVoter extends Voter
{
    public const string VIEW = 'DOCUMENT_VIEW';
    public const string OWN = 'DOCUMENT_OWN';

    public function __construct(private readonly DocumentKeyGrantRepository $grants)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::OWN], true) && $subject instanceof Document;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        return match ($attribute) {
            self::OWN => $subject->getOwner()->is($user),
            self::VIEW => $subject->getOwner()->is($user) || $this->grants->hasGrantForDocument($subject, $user),
            default => false,
        };
    }
}
