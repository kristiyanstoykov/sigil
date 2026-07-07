<?php

declare(strict_types=1);

namespace App\AuditLog\Repository;

use App\AuditLog\Entity\AuditLogEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
class AuditLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    /**
     * Last entry in the chain, locked against concurrent appends.
     * Must be called inside an open transaction.
     */
    public function findChainHeadForUpdate(): ?AuditLogEntry
    {
        /** @var AuditLogEntry|null */
        return $this->getEntityManager()->createQuery(
            'SELECT e FROM '.AuditLogEntry::class.' e ORDER BY e.sequence DESC'
        )
            ->setMaxResults(1)
            ->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Streams the chain in sequence order for verification.
     *
     * @return iterable<AuditLogEntry>
     */
    public function iterateChain(): iterable
    {
        return $this->createQueryBuilder('e')
            ->orderBy('e.sequence', 'ASC')
            ->getQuery()
            ->toIterable();
    }
}
