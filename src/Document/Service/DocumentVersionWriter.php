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
use App\Document\Repository\DocumentKeyGrantRepository;
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
        private readonly DocumentKeyGrantRepository $grants,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param User                  $actor        who is writing this version - the audit actor, and always a grantee
     * @param string                $bytes        plaintext file bytes (already in memory)
     * @param array<string, scalar> $auditPayload merged into the audit entry (e.g. a title)
     */
    public function write(
        Document $document,
        User $actor,
        string $bytes,
        DocumentVersionKind $kind,
        string $auditAction,
        array $auditPayload = [],
    ): DocumentVersion {
        // Capture the outgoing latest BEFORE constructing the new version: the
        // constructor adds itself to the document's collection, so afterwards
        // getLatestVersion() is the new one. Same reason nextVersionNumber()
        // is read here.
        $previous = $document->getLatestVersion();

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

            $this->em->persist($version);

            // One grant per user who may read this version: the DEK is fresh, so
            // each grantee simply gets it wrapped under their own KEK.
            foreach ($this->granteesFor($document, $previous, $actor) as $grantee) {
                $this->em->persist(new DocumentKeyGrant(
                    $version,
                    $grantee,
                    $this->keys->wrapDek($grantee, $dek, $version->dekAad()),
                ));
            }

            $this->em->flush();
        } finally {
            sodium_memzero($dek);
        }

        $this->auditLogger->log(
            action: $auditAction,
            actor: $actor,
            payload: array_merge(['versionNumber' => $version->getVersionNumber(), 'sizeBytes' => \strlen($bytes)], $auditPayload),
            subjectType: 'Document',
            subjectId: $document->getId()->toRfc4122(),
        );

        return $version;
    }

    /**
     * Who gets a grant on the new version: the document's owner, everyone who
     * could read the version before it, and the actor writing this one.
     *
     * Access has to carry forward, or the act of signing would redraw it - a
     * signed version would be readable only by whoever signed, silently cutting
     * off the owner and anyone the document was shared with. Carrying it forward
     * also means a revoke (which deletes grant rows) is not undone by the next
     * version: the revoked user is no longer in the previous version's set.
     *
     * @return list<User>
     */
    private function granteesFor(Document $document, ?DocumentVersion $previous, User $actor): array
    {
        $grantees = [$document->getOwner(), $actor];

        if (null !== $previous) {
            $grantees = array_merge($grantees, $this->grants->findUsersForVersion($previous));
        }

        // Dedupe by id: the same user arrives here by more than one route
        // (owner uploading, owner signing their own document, ...).
        $unique = [];
        foreach ($grantees as $grantee) {
            $unique[$grantee->getId()->toRfc4122()] = $grantee;
        }

        return array_values($unique);
    }
}
