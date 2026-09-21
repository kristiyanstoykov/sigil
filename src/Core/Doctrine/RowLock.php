<?php

declare(strict_types=1);

namespace App\Core\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Serialise a state transition: take a row-level write lock on each entity
 * (SELECT ... FOR UPDATE) and reload it, so the predicate checked next is
 * evaluated against what is committed, not against what this request loaded a
 * moment ago. Must run inside the transaction that will make the change; the
 * lock is released with it. Lock the Document before its SigningRequest,
 * always, so two workflows never wait on each other.
 */
final class RowLock
{
    public static function acquire(EntityManagerInterface $em, object ...$entities): void
    {
        foreach ($entities as $entity) {
            $em->lock($entity, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($entity);
        }
    }
}
