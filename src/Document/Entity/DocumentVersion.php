<?php

declare(strict_types=1);

namespace App\Document\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Document\Enum\DocumentVersionKind;
use App\Document\Repository\DocumentVersionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One immutable version of a document's bytes. Each version has its OWN random
 * DEK (never stored on this row - it lives wrapped in {@see DocumentKeyGrant},
 * one grant per user with access). The bytes are stored as AES-256-GCM
 * ciphertext under $storageKey via DocumentStorageInterface; object storage
 * only ever holds ciphertext (ADR-004).
 *
 * $contentHash is the SHA-384 of the *plaintext*, kept for the evidentiary
 * story (integrity check independent of the ciphertext).
 */
#[ORM\Entity(repositoryClass: DocumentVersionRepository::class)]
#[ORM\Table(name: 'document_version')]
#[ORM\UniqueConstraint(name: 'uniq_document_version_number', columns: ['document_id', 'version_number'])]
#[ORM\HasLifecycleCallbacks]
class DocumentVersion
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false)]
    private Document $document;

    #[ORM\Column(name: 'version_number')]
    private int $versionNumber;

    #[ORM\Column(enumType: DocumentVersionKind::class)]
    private DocumentVersionKind $kind;

    /** Opaque storage key for the ciphertext blob. Not user-controlled. */
    #[ORM\Column(length: 255, unique: true)]
    private string $storageKey;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    /** Plaintext size in bytes. */
    #[ORM\Column]
    private int $sizeBytes;

    /** SHA-384 hex of the plaintext bytes. */
    #[ORM\Column(length: 96)]
    private string $contentHash;

    public function __construct(
        Document $document,
        int $versionNumber,
        DocumentVersionKind $kind,
        string $storageKey,
        string $mimeType,
        int $sizeBytes,
        string $contentHash,
    ) {
        $this->initUuid();
        $this->document = $document;
        $this->versionNumber = $versionNumber;
        $this->kind = $kind;
        $this->storageKey = $storageKey;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->contentHash = $contentHash;
        $document->addVersion($this);
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getKind(): DocumentVersionKind
    {
        return $this->kind;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    /** Stable per-version context bound into the DEK's AEAD (see KeyManagementService). */
    public function dekAad(): string
    {
        return 'dek:'.$this->getId()->toRfc4122();
    }
}
