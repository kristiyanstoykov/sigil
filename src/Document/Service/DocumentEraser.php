<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentKeyGrantRepository;
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
        private readonly DocumentKeyGrantRepository $grants,
    ) {
    }

    public function erase(Document $document, ?User $actor = null, string $reason = 'deleted'): void
    {
        $documentId = $document->getId()->toRfc4122();
        $versions = $document->getVersions()->toArray();
        $storageKeys = [];
        foreach ($versions as $version) {
            $storageKeys[] = $version->getStorageKey();
        }

        $grants = $this->grants->findForDocument($document);

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

        // Removed through the unit of work, deepest first, so Doctrine drops all
        // three from the identity map on flush. A bulk DQL DELETE would empty
        // the tables behind its back and leave the caller - usually mid-sweep,
        // still holding other entities - with a version pointing at a document
        // Doctrine no longer knows, which reads as a new entity and aborts the
        // next flush.
        foreach ($grants as $grant) {
            $this->em->remove($grant);
        }
        foreach ($versions as $version) {
            $this->em->remove($version);
        }

        // Signing requests and their signer rows go with it: both cascade from
        // the document at the database level, so the ORM never sees them.
        $this->em->remove($document);
        $this->em->flush();

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
