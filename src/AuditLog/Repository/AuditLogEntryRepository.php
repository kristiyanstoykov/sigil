<?php

declare(strict_types=1);

namespace App\AuditLog\Repository;

use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Enum\AuditSeverity;
use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuditLogEntry>
 */
class AuditLogEntryRepository extends ServiceEntityRepository
{
    /**
     * Advisory lock key for the chain: "SIGAUD" as bytes. Arbitrary but fixed -
     * every appender has to name the same number or they do not exclude one
     * another.
     */
    public const int CHAIN_LOCK_KEY = 0x534947415544;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLogEntry::class);
    }

    /**
     * Serialises appends to the chain. Must be taken before reading the head,
     * inside the transaction that will write the next entry.
     *
     * A row lock on the head cannot do this, which is what the code used to try.
     * Under READ COMMITTED a second transaction blocks on the locked row, then
     * continues with the result set it had already computed - so it still
     * believes the old head is current and inserts a duplicate sequence. The
     * lock has to cover the *gap* where the next row will go, and there is no
     * row there to lock, so it is taken on the chain as a whole. It also covers
     * the empty-table case, where there is no head to lock at all.
     *
     * Transaction-scoped: Postgres releases it on commit or rollback, so there
     * is no unlock path that can leak one. If a caller already had a transaction
     * open, that is the one it is held to.
     */
    public function lockChainForAppend(): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'SELECT pg_advisory_xact_lock(?)',
            [self::CHAIN_LOCK_KEY],
        );
    }

    /**
     * Last entry in the chain. Call {@see lockChainForAppend()} first when the
     * answer is about to be used to compute the next sequence.
     */
    public function findChainHead(): ?AuditLogEntry
    {
        /** @var AuditLogEntry|null */
        return $this->getEntityManager()->createQuery(
            'SELECT e FROM '.AuditLogEntry::class.' e ORDER BY e.sequence DESC'
        )
            ->setMaxResults(1)
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
     * The log as one person may read it: what they did, and what happened to
     * what is theirs - their documents and their certificates - including what
     * others did to them (a signer's signature, a decliner's refusal). Never
     * another user's actions on another user's things, and never the
     * system-wide rows (CA init, anchors): those have no actor and no owner.
     *
     * @param list<string> $documentIds   rfc4122 ids of documents the user owns
     * @param list<string> $certificateIds rfc4122 ids of the user's certificates
     * @param list<string> $actions        restrict to these actions; empty = all
     *
     * @return list<AuditLogEntry>
     */
    public function findVisibleTo(User $user, array $documentIds, array $certificateIds, array $actions, ?AuditSeverity $severity, int $limit, int $offset): array
    {
        $qb = $this->visibleTo($user, $documentIds, $certificateIds, $actions, $severity)
            ->orderBy('e.sequence', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<string> $documentIds
     * @param list<string> $certificateIds
     * @param list<string> $actions
     */
    public function countVisibleTo(User $user, array $documentIds, array $certificateIds, array $actions, ?AuditSeverity $severity): int
    {
        return (int) $this->visibleTo($user, $documentIds, $certificateIds, $actions, $severity)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<string> $documentIds
     * @param list<string> $certificateIds
     * @param list<string> $actions
     */
    private function visibleTo(User $user, array $documentIds, array $certificateIds, array $actions, ?AuditSeverity $severity): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');
        $or = ['e.actorId = :actor'];
        $qb->setParameter('actor', $user->getId(), 'uuid');
        if ([] !== $documentIds) {
            $or[] = "(e.subjectType = 'Document' AND e.subjectId IN (:documents))";
            $qb->setParameter('documents', $documentIds);
        }
        if ([] !== $certificateIds) {
            $or[] = "(e.subjectType = 'Certificate' AND e.subjectId IN (:certificates))";
            $qb->setParameter('certificates', $certificateIds);
        }
        $qb->andWhere(implode(' OR ', $or));

        if ([] !== $actions) {
            $qb->andWhere('e.action IN (:actions)')->setParameter('actions', $actions);
        }
        if (null !== $severity) {
            $qb->andWhere('e.severity = :severity')->setParameter('severity', $severity->value);
        }

        return $qb;
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
