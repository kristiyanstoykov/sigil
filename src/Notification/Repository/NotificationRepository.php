<?php

declare(strict_types=1);

namespace App\Notification\Repository;

use App\Core\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
final class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * The bell dropdown and the notifications page read the same list, newest
     * first; only the limit differs.
     *
     * @return list<Notification>
     */
    public function findRecentFor(User $user, int $limit = 10, int $offset = 0): array
    {
        return $this->inboxQuery($user)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countFor(User $user): int
    {
        return (int) $this->inboxQuery($user)
            ->select('COUNT(n.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** What the bell badge counts. */
    public function countUnreadFor(User $user): int
    {
        return (int) $this->inboxQuery($user)
            ->select('COUNT(n.id)')
            ->resetDQLPart('orderBy')
            ->andWhere('n.readAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Unread notifications of the given types, newest first. The dashboard's
     * "needs your action" reads this: an unread row is the whole state, which is
     * why it can clear itself without anything being recorded about the document.
     *
     * @param list<NotificationType> $types
     *
     * @return list<Notification>
     */
    public function findUnreadFor(User $user, array $types = [], int $limit = 20): array
    {
        $qb = $this->inboxQuery($user)
            ->andWhere('n.readAt IS NULL')
            ->setMaxResults($limit);

        if ([] !== $types) {
            $qb->andWhere('n.type IN (:types)')->setParameter('types', $types);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Mark everything unread as read. Reads the rows and mutates them rather than
     * issuing a bulk UPDATE: a DQL update never reaches the unit of work, so the
     * entities the request is already holding would silently disagree with the
     * database.
     */
    public function markAllReadFor(User $user, \DateTimeImmutable $at): int
    {
        $unread = $this->findUnreadFor($user, [], 500);
        foreach ($unread as $notification) {
            $notification->markRead($at);
        }

        if ([] !== $unread) {
            $this->getEntityManager()->flush();
        }

        return \count($unread);
    }

    private function inboxQuery(User $user): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.recipient = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->addOrderBy('n.id', 'DESC');
    }
}
