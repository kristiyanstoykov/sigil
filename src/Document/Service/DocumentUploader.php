<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Enum\DocumentVersionKind;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Uploads a document: validate → persist the Document → delegate to
 * {@see DocumentVersionWriter} for the original version (DEK + encrypt + store +
 * grant + audit). Sync, transactional (ADR-004/006). Buffers the whole file in
 * memory, which the 10 MB limit keeps bounded.
 */
final class DocumentUploader
{
    public function __construct(
        private readonly DocumentVersionWriter $versionWriter,
        private readonly DocumentNotifier $notifier,
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
        $this->em->persist($document);

        $this->versionWriter->write(
            $document,
            $owner,
            $bytes,
            DocumentVersionKind::Original,
            'document.uploaded',
            ['title' => $document->getTitle()],
        );

        $this->notifier->notifyUploaded($document);

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
