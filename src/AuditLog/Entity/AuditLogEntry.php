<?php

declare(strict_types=1);

namespace App\AuditLog\Entity;

use App\AuditLog\Enum\AuditSeverity;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\AuditLog\Service\AuditChainHasher;
use App\Core\Entity\Trait\HasUuid;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only, hash-chained audit record (see "Security invariants").
 *
 * entryHash = hash(previousHash . canonicalPayload) under the scheme the entry
 * names (SHA256/v1 today, {@see AuditChainHasher}). Entries are never
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

    /** Which chain scheme produced entryHash ({@see AuditChainHasher}); verified with the same one. */
    #[ORM\Column(length: 32, options: ['default' => AuditChainHasher::SCHEME])]
    private string $hashScheme = AuditChainHasher::SCHEME;

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
        $this->entryHash = AuditChainHasher::hash($this->hashScheme, $previousHash, $this->canonicalPayload());
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
            // The column is a naive timestamp holding UTC wall-clock time (the
            // logger writes UTC). A reload rebuilds it in PHP's default zone, so
            // converting would shift the hours; hash the wall-clock fields as
            // stored and stamp the +00:00 they mean. Byte-identical to the
            // RFC3339_EXTENDED form every existing entry was hashed with.
            'occurredAt' => $this->occurredAt->format('Y-m-d\TH:i:s.v').'+00:00',
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

    public function getHashScheme(): string
    {
        return $this->hashScheme;
    }

    /**
     * Re-derive this entry's hash from its stored content and the given
     * predecessor. ONLY for sigil:audit:rechain, which repairs a chain whose
     * hashes were computed over data the database never kept; the content
     * itself is untouched and the repair is itself audited.
     */
    public function relink(string $previousHash): void
    {
        $this->previousHash = $previousHash;
        $this->hashScheme = AuditChainHasher::SCHEME;
        $this->entryHash = AuditChainHasher::hash($this->hashScheme, $previousHash, $this->canonicalPayload());
    }
}
