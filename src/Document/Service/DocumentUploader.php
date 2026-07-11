<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentKeyGrant;
use App\Document\Entity\DocumentVersion;
use App\Document\Enum\DocumentVersionKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Uploads a document: validate → per-version DEK → encrypt bytes → store
 * ciphertext → persist Document + original DocumentVersion + owner DocumentKeyGrant
 * → audit. Sync, transactional (ADR-004/006). Buffers the whole file in memory,
 * which the 10 MB limit keeps bounded.
 */
final class DocumentUploader
{
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly ContentHasher $contentHasher,
        private readonly KeyManagementService $keys,
        private readonly DocumentStorageInterface $storage,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $em,
        #[Autowire('%app.max_document_size_bytes%')]
        private readonly int $maxSizeBytes,
    ) {
    }

    /**
     * @param string $bytes            raw file bytes (already read into memory)
     * @param string $originalFilename client-supplied name - used only as a display title, never as a path
     *
     * @throws DomainException on an invalid/oversized/non-PDF upload
     */
    public function upload(User $owner, string $bytes, string $originalFilename): Document
    {
        $this->assertValidPdf($bytes);

        $document = new Document($owner, self::sanitizeTitle($originalFilename));
        $version = new DocumentVersion(
            document: $document,
            versionNumber: 1,
            kind: DocumentVersionKind::Original,
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

            $this->em->persist($document);
            $this->em->persist($version);
            $this->em->persist($grant);
            $this->em->flush();
        } finally {
            sodium_memzero($dek);
        }

        $this->auditLogger->log(
            action: 'document.uploaded',
            actor: $owner,
            payload: ['title' => $document->getTitle(), 'sizeBytes' => \strlen($bytes)],
            subjectType: 'Document',
            subjectId: $document->getId()->toRfc4122(),
        );

        return $document;
    }

    /**
     * Accept only real PDFs: size-bounded, correct magic bytes, and a sniffed
     * MIME of application/pdf - never trust the client-supplied name or type.
     */
    private function assertValidPdf(string $bytes): void
    {
        $size = \strlen($bytes);
        if (0 === $size) {
            throw new DomainException('The uploaded file is empty.');
        }
        if ($size > $this->maxSizeBytes) {
            throw new DomainException(sprintf('The file exceeds the %d MB limit.', intdiv($this->maxSizeBytes, 1024 * 1024)));
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new DomainException('Only PDF files are accepted.');
        }
        if ('application/pdf' !== (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes)) {
            throw new DomainException('Only PDF files are accepted.');
        }
    }

    /** Client filename → safe display title. Strips any path and control chars. */
    private static function sanitizeTitle(string $filename): string
    {
        $title = trim(preg_replace('/[\x00-\x1F\x7F]/', '', basename($filename)) ?? '');

        return '' === $title ? 'document.pdf' : mb_substr($title, 0, 255);
    }
}
