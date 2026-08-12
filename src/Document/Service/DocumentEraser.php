<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentKeyGrant;
use App\Document\Entity\DocumentVersion;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Destroys a document for good: grants, versions, the row itself and the stored
 * ciphertext. Used by the signing sweep, which deletes a document nobody ever
 * signed once its deadline passes.
 *
 * Rows go first and the bytes second: an orphaned blob is recoverable garbage,
 * whereas a row pointing at bytes that are already gone is a broken document.
 */
final class DocumentEraser
{
    public function __construct(
        private readonly DocumentStorageInterface $storage,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function erase(Document $document, ?User $actor = null, string $reason = 'deleted'): void
    {
        $documentId = $document->getId()->toRfc4122();
        $storageKeys = [];
        foreach ($document->getVersions() as $version) {
            $storageKeys[] = $version->getStorageKey();
        }

        $this->auditLogger->log(
            action: 'document.erased',
            actor: $actor,
            payload: [
                'title' => $document->getTitle(),
                'versions' => \count($storageKeys),
                'reason' => $reason,
            ],
            subjectType: 'Document',
            subjectId: $documentId,
        );

        $this->em->createQuery(
            'DELETE FROM '.DocumentKeyGrant::class.' g
             WHERE g.version IN (SELECT v.id FROM '.DocumentVersion::class.' v WHERE v.document = :document)'
        )->setParameter('document', $document)->execute();

        $this->em->createQuery('DELETE FROM '.DocumentVersion::class.' v WHERE v.document = :document')
            ->setParameter('document', $document)
            ->execute();

        // Signing requests and their signer rows go with it: both cascade from
        // the document at the database level.
        $this->em->createQuery('DELETE FROM '.Document::class.' d WHERE d.id = :id')
            ->setParameter('id', $document->getId())
            ->execute();

        // Detach rather than clear: the caller is usually mid-sweep and still
        // holds other entities it needs.
        $this->em->detach($document);

        foreach ($storageKeys as $key) {
            try {
                $this->storage->delete($key);
            } catch (\Throwable $e) {
                // The document is already gone from the database; a blob we
                // cannot reach is not a reason to fail the sweep.
                $this->logger->error('Could not delete document ciphertext {key}: {message}', [
                    'key' => $key,
                    'message' => $e->getMessage(),
                    'documentId' => $documentId,
                ]);
            }
        }
    }
}
