<?php

declare(strict_types=1);

namespace App\AuditLog\Service;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Enum\AuditSeverity;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Core\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Appends to the hash chain inside its own transaction. The chain head is
 * read under a pessimistic write lock so concurrent appends serialize and
 * the chain cannot fork.
 */
final class DoctrineAuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogEntryRepository $repository,
        private readonly ClockInterface $clock,
    ) {
    }

    public function log(
        string $action,
        ?User $actor = null,
        array $payload = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
        AuditSeverity $severity = AuditSeverity::Info,
    ): AuditLogEntry {
        return $this->em->wrapInTransaction(function () use ($action, $actor, $payload, $subjectType, $subjectId, $severity): AuditLogEntry {
            $head = $this->repository->findChainHeadForUpdate();

            $entry = new AuditLogEntry(
                sequence: null === $head ? 1 : $head->getSequence() + 1,
                previousHash: $head?->getEntryHash() ?? AuditLogEntry::GENESIS_HASH,
                action: $action,
                actorId: $actor?->getId(),
                subjectType: $subjectType,
                subjectId: $subjectId,
                payload: $payload,
                severity: $severity,
                occurredAt: \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC')),
            );

            $this->em->persist($entry);

            return $entry;
        });
    }
}
