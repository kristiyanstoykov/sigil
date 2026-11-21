<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\AccountClosureRecord;
use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountClosureRecord>
 */
final class AccountClosureRecordRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountClosureRecord::class);
    }

    /** @return list<AccountClosureRecord> newest first */
    public function findForUser(User $user): array
    {
        /** @var list<AccountClosureRecord> */
        return $this->findBy(['user' => $user], ['sealedAt' => 'DESC']);
    }
}
