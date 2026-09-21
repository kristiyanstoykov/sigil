<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The authenticated user, typed. Controllers used to carry a private
 * currentUser() each (getUser() + assert); Twig extensions each re-check
 * instanceof. This is the one spelling of both.
 */
final class CurrentUser
{
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * For pages behind the firewall: the user is there or the request should
     * not have reached the code asking.
     *
     * @throws AccessDeniedException when nobody is authenticated
     */
    public function get(): User
    {
        return $this->getOrNull() ?? throw new AccessDeniedException('Authentication required.');
    }

    /** For places that render for guests too (layout, Twig functions). */
    public function getOrNull(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
