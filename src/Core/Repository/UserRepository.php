<?php

declare(strict_types=1);

namespace App\Core\Repository;

use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Look a user up by the email someone typed. Matched case-insensitively on
     * purpose: registration stores the address as entered, so "Ann@x.com" and
     * "ann@x.com" are the same person to everyone except a plain equality check.
     */
    public function findOneByEmail(string $email): ?User
    {
        return $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) = :email')
            ->setParameter('email', mb_strtolower(trim($email)))
            ->getQuery()
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /**
     * Users by lower-cased email, for many addresses at once.
     *
     * @param list<string> $emails lower-cased
     *
     * @return array<string, User> keyed by lower-cased email
     */
    public function findByEmailsKeyed(array $emails): array
    {
        if ([] === $emails) {
            return [];
        }

        /** @var list<User> $users */
        $users = $this->createQueryBuilder('u')
            ->andWhere('LOWER(u.email) IN (:emails)')
            ->setParameter('emails', $emails)
            ->getQuery()
            ->getResult();

        $keyed = [];
        foreach ($users as $user) {
            $keyed[mb_strtolower($user->getEmail())] = $user;
        }

        return $keyed;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
