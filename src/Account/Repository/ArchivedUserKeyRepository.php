<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\ArchivedUserKey;
use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ArchivedUserKey>
 */
final class ArchivedUserKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ArchivedUserKey::class);
    }

    public function findForUser(User $user): ?ArchivedUserKey
    {
        return $this->findOneBy(['user' => $user]);
    }
}
