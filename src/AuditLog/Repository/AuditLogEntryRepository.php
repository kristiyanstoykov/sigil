<?php

declare(strict_types=1);

namespace App\AuditLog\Repository;

use App\AuditLog\Entity\AuditLogEntry;
use App\Core\Entity\User;
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
     * What this user did lately, newest first - the dashboard's activity feed.
     * The log is already the record of everything that happened, so the feed is
     * a read of it rather than a second store.
     *
     * @param list<string> $actions limit to these actions; empty means all
     *
     * @return list<AuditLogEntry>
     */
    public function findRecentForActor(User $actor, int $limit = 8, array $actions = []): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.actorId = :actor')
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->orderBy('e.sequence', 'DESC')
            ->setMaxResults($limit);

        if ([] !== $actions) {
            $qb->andWhere('e.action IN (:actions)')->setParameter('actions', $actions);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Counts of the given actions per calendar month since $since, for one actor:
     * `['document.uploaded' => ['2026-03' => 4, ...], ...]`.
     *
     * Grouped in PHP rather than SQL on purpose - month truncation is not
     * portable DQL, and this reads at most a few hundred rows for one user.
     *
     * @param list<string> $actions
     *
     * @return array<string, array<string, int>>
     */
    public function countPerMonthForActor(User $actor, array $actions, \DateTimeImmutable $since): array
    {
        /** @var list<array{action: string, occurredAt: \DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('e')
            ->select('e.action AS action', 'e.occurredAt AS occurredAt')
            ->andWhere('e.actorId = :actor')
            ->andWhere('e.action IN (:actions)')
            ->andWhere('e.occurredAt >= :since')
            ->setParameter('actor', $actor->getId(), 'uuid')
            ->setParameter('actions', $actions)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();

        $counts = array_fill_keys($actions, []);
        foreach ($rows as $row) {
            $month = $row['occurredAt']->format('Y-m');
            $counts[$row['action']][$month] = ($counts[$row['action']][$month] ?? 0) + 1;
        }

        return $counts;
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
