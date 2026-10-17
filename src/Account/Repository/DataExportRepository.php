<?php

declare(strict_types=1);

namespace App\Account\Repository;

use App\Account\Entity\DataExport;
use App\Account\Enum\DataExportStatus;
use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataExport>
 */
final class DataExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataExport::class);
    }

    public function findLatestFor(User $owner): ?DataExport
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasInProgress(User $owner): bool
    {
        return null !== $this->createQueryBuilder('e')
            ->select('e.id')
            ->andWhere('e.owner = :owner')
            ->andWhere('e.status IN (:statuses)')
            ->setParameter('owner', $owner)
            ->setParameter('statuses', [DataExportStatus::Queued, DataExportStatus::Building])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
