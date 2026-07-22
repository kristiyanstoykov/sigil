<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentKeyGrant;
use App\Document\Entity\DocumentVersion;
use App\Document\Enum\DocumentVersionKind;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mints one new {@see DocumentVersion} for an existing {@see Document}: fresh
 * per-version DEK → AES-256-GCM encrypt the bytes → store ciphertext → persist
 * the version + the owner's {@see DocumentKeyGrant} → audit (ADR-004/006).
 *
 * The single place a version's bytes become an encrypted, granted, audited row -
 * shared by {@see DocumentUploader} (the original) and the signing flow (each
 * signed version), so both mint keys and grants identically. The caller owns the
 * {@see Document}'s own lifecycle (persist a brand-new one before calling here;
 * an existing one is already managed).
 */
final class DocumentVersionWriter
{
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly ContentHasher $contentHasher,
        private readonly KeyManagementService $keys,
        private readonly DocumentStorageInterface $storage,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param string               $bytes        plaintext file bytes (already in memory)
     * @param array<string, scalar> $auditPayload merged into the audit entry (e.g. a title)
     */
    public function write(
        Document $document,
        User $owner,
        string $bytes,
        DocumentVersionKind $kind,
        string $auditAction,
        array $auditPayload = [],
    ): DocumentVersion {
        // Read the next number BEFORE constructing: DocumentVersion's constructor
        // adds itself to the document's version collection.
        $version = new DocumentVersion(
            document: $document,
            versionNumber: $document->nextVersionNumber(),
            kind: $kind,
            storageKey: '',
            mimeType: 'application/pdf',
            sizeBytes: \strlen($bytes),
            contentHash: $this->contentHasher->hash($bytes),
        );

        $dek = $this->encryption->generateKey();
        try {
            $envelope = $this->encryption->encrypt($bytes, $dek, $version->dekAad());
            $version->setStorageKey($this->storage->store($envelope));

            $grant = new DocumentKeyGrant(
                $version,
                $owner,
                $this->keys->wrapDek($owner, $dek, $version->dekAad()),
            );

            $this->em->persist($version);
            $this->em->persist($grant);
            $this->em->flush();
        } finally {
            sodium_memzero($dek);
        }

        $this->auditLogger->log(
            action: $auditAction,
            actor: $owner,
            payload: array_merge(['versionNumber' => $version->getVersionNumber(), 'sizeBytes' => \strlen($bytes)], $auditPayload),
            subjectType: 'Document',
            subjectId: $document->getId()->toRfc4122(),
        );

        return $version;
    }
}
