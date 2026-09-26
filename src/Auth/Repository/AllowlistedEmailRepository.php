<?php

declare(strict_types=1);

namespace App\Auth\Repository;

use App\Auth\Entity\AllowlistedEmail;
use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AllowlistedEmail>
 */
class AllowlistedEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AllowlistedEmail::class);
    }

    public function findOneByEmail(string $email): ?AllowlistedEmail
    {
        return $this->findOneBy(['email' => AllowlistedEmail::normalize($email)]);
    }

    /** @return list<AllowlistedEmail> newest first */
    public function findPage(int $limit, int $offset): array
    {
        /** @var list<AllowlistedEmail> */
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.email', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /** How many listed addresses already have an account. */
    public function countRegistered(): int
    {
        return (int) $this->getEntityManager()
            ->createQuery('SELECT COUNT(a.id) FROM '.AllowlistedEmail::class.' a, '.User::class.' u WHERE LOWER(u.email) = a.email')
            ->getSingleScalarResult();
    }
}
