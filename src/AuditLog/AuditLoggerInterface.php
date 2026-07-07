<?php

declare(strict_types=1);

namespace App\AuditLog;

use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Enum\AuditSeverity;
use App\Core\Entity\User;

/**
 * The single entry point other modules use to write audit records.
 *
 * Implementations MUST maintain the hash chain
 * (entryHash = sha256(previousHash . canonicalPayload)) and MUST be
 * append-only. AuditLog depends on Core only; every other module calls it.
 */
interface AuditLoggerInterface
{
    /**
     * @param string               $action      dotted verb, e.g. "certificate.issued", "auth.pin.failed"
     * @param array<string, mixed> $payload     JSON-safe context; never include secrets, PINs or key material
     * @param string|null          $subjectType e.g. "Certificate"
     * @param string|null          $subjectId   UUID / serial of the affected aggregate
     */
    public function log(
        string $action,
        ?User $actor = null,
        array $payload = [],
        ?string $subjectType = null,
        ?string $subjectId = null,
        AuditSeverity $severity = AuditSeverity::Info,
    ): AuditLogEntry;
}
