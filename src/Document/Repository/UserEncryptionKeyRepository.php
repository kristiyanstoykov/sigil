<?php

declare(strict_types=1);

namespace App\Document\Repository;

use App\Core\Entity\User;
use App\Document\Entity\UserEncryptionKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserEncryptionKey>
 */
final class UserEncryptionKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserEncryptionKey::class);
    }

    public function findForUser(User $user): ?UserEncryptionKey
    {
        return $this->findOneBy(['user' => $user]);
    }
}
