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
     * The entries recording what happened to one subject, in sequence order.
     *
     * This is the evidence set a delivery receipt renders: the log is the
     * repository of evidence, the receipt is a sealed extract of it (ADR-012).
     *
     * @return list<AuditLogEntry>
     */
    public function findForSubject(string $subjectType, string $subjectId): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.subjectType = :type')
            ->andWhere('e.subjectId = :id')
            ->setParameter('type', $subjectType)
            ->setParameter('id', $subjectId)
            ->orderBy('e.sequence', 'ASC')
            ->getQuery()
            ->getResult();
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
