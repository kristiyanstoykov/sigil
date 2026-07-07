<?php

declare(strict_types=1);

namespace App\AuditLog\Entity;

use App\AuditLog\Enum\AuditSeverity;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Core\Entity\Trait\HasUuid;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only, hash-chained audit record (see "Security invariants").
 *
 * entryHash = sha256(previousHash . canonicalPayload). Entries are never
 * updated or deleted; there are deliberately no setters. Chain integrity is
 * verified with `sigil:audit:verify`.
 */
#[ORM\Entity(repositoryClass: AuditLogEntryRepository::class)]
#[ORM\Table(name: 'audit_log_entry')]
#[ORM\Index(columns: ['action'], name: 'idx_audit_action')]
#[ORM\Index(columns: ['actor_id'], name: 'idx_audit_actor')]
#[ORM\Index(columns: ['subject_type', 'subject_id'], name: 'idx_audit_subject')]
#[ORM\HasLifecycleCallbacks]
class AuditLogEntry
{
    use HasUuid;

    public const string GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    #[ORM\Column(type: 'bigint', unique: true)]
    private string $sequence;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** Not a FK on purpose: audit entries must survive user erasure (GDPR crypto-shred). */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $actorId;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $subjectType;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $subjectId;

    #[ORM\Column(length: 20, enumType: AuditSeverity::class)]
    private AuditSeverity $severity;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $payload;

    #[ORM\Column(length: 64)]
    private string $previousHash;

    #[ORM\Column(length: 64, unique: true)]
    private string $entryHash;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        int $sequence,
        string $previousHash,
        string $action,
        ?Uuid $actorId,
        ?string $subjectType,
        ?string $subjectId,
        array $payload,
        AuditSeverity $severity,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->sequence = (string) $sequence;
        $this->previousHash = $previousHash;
        $this->action = $action;
        $this->actorId = $actorId;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->payload = $payload;
        $this->severity = $severity;
        $this->occurredAt = $occurredAt;
        $this->entryHash = hash('sha256', $previousHash.$this->canonicalPayload());
    }

    /**
     * Deterministic serialization of everything the hash must protect.
     * Key order is fixed; payload keys are sorted recursively.
     */
    public function canonicalPayload(): string
    {
        $payload = $this->payload;
        self::ksortRecursive($payload);

        return json_encode([
            'sequence' => (int) $this->sequence,
            'occurredAt' => $this->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED),
            'actorId' => $this->actorId?->toRfc4122(),
            'action' => $this->action,
            'subjectType' => $this->subjectType,
            'subjectId' => $this->subjectId,
            'severity' => $this->severity->value,
            'payload' => $payload,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<array-key, mixed> $array
     */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (\is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }

    public function getSequence(): int
    {
        return (int) $this->sequence;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorId(): ?Uuid
    {
        return $this->actorId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?string
    {
        return $this->subjectId;
    }

    public function getSeverity(): AuditSeverity
    {
        return $this->severity;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getPreviousHash(): string
    {
        return $this->previousHash;
    }

    public function getEntryHash(): string
    {
        return $this->entryHash;
    }
}
