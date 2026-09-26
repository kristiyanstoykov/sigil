<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Auth\Entity\AllowlistedEmail;
use App\Auth\Repository\AllowlistedEmailRepository;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Who may register. Registration is invitation-only, so this list is the gate
 * (AuthController::register) and the admin page is the only way onto it.
 */
final readonly class RegistrationAllowlist
{
    public function __construct(
        private AllowlistedEmailRepository $entries,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private AuditLoggerInterface $audit,
    ) {}

    public function permits(string $email): bool
    {
        return null !== $this->entries->findOneByEmail($email);
    }

    /** @throws DomainException when the address is already on the list */
    public function add(string $email, ?User $by = null): AllowlistedEmail
    {
        if ($this->permits($email)) {
            throw new DomainException('That address is already on the allowlist.');
        }

        $entry = new AllowlistedEmail($email, $by?->getId());
        $this->em->wrapInTransaction(function () use ($entry, $by): void {
            $this->em->persist($entry);
            $this->em->flush();
            $this->audit->log(
                action: 'admin.allowlist_added',
                actor: $by,
                payload: ['email' => $entry->getEmail()],
                subjectType: 'AllowlistedEmail',
                subjectId: $entry->getId()->toRfc4122(),
            );
        });

        return $entry;
    }

    /**
     * Only an invitation nobody has used yet can be withdrawn: removing a
     * registered address would not touch the account, so the list would lie.
     *
     * @throws DomainException when the address already has an account
     */
    public function remove(AllowlistedEmail $entry, User $by): void
    {
        if (null !== $this->users->findOneByEmail($entry->getEmail())) {
            throw new DomainException('That address already has an account, so its invitation cannot be withdrawn.');
        }

        $this->em->wrapInTransaction(function () use ($entry, $by): void {
            $this->audit->log(
                action: 'admin.allowlist_removed',
                actor: $by,
                payload: ['email' => $entry->getEmail()],
                subjectType: 'AllowlistedEmail',
                subjectId: $entry->getId()->toRfc4122(),
            );
            $this->em->remove($entry);
            $this->em->flush();
        });
    }
}
