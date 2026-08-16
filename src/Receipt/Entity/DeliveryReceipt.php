<?php

declare(strict_types=1);

namespace App\Receipt\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Receipt\Enum\ReceiptOutcome;
use App\Receipt\Enum\ReceiptSource;
use App\Receipt\Repository\DeliveryReceiptRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Sigil's attestation that a document reached people and what became of it - the
 * "return receipt" half of registered delivery (eIDAS Art. 43-44,
 * ETSI EN 319 522; see ADR-012).
 *
 * Two things produce one: a closed signature request, or a delivery. $source
 * says which, and $sourceId points at it.
 *
 * The stored PDF is sealed with the application's own certificate, encrypted
 * under its own DEK and readable by everyone who took part, through a
 * {@see DeliveryReceiptKeyGrant}.
 *
 * Document and source are plain UUID columns rather than foreign keys, for the
 * same reason {@see \App\AuditLog\Entity\AuditLogEntry::$actorId} is: a receipt
 * outlives its document. An unsigned request that expires has its document
 * erased, and the receipt attesting that is precisely what has to survive.
 */
#[ORM\Entity(repositoryClass: DeliveryReceiptRepository::class)]
#[ORM\Table(name: 'delivery_receipt')]
#[ORM\Index(columns: ['document_id'], name: 'idx_receipt_document')]
#[ORM\HasLifecycleCallbacks]
class DeliveryReceipt
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\Column(type: 'uuid')]
    private Uuid $documentId;

    #[ORM\Column(enumType: ReceiptSource::class)]
    private ReceiptSource $source;

    /**
     * The request or delivery being attested. Unique: a request closes exactly
     * once, and a delivery is made exactly once.
     */
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $sourceId;

    /** Snapshot - the document may not exist by the time anyone reads this. */
    #[ORM\Column(length: 255)]
    private string $documentTitle;

    /** SHA-384 of the delivered document version, hex. */
    #[ORM\Column(length: 96)]
    private string $documentHash;

    #[ORM\Column(enumType: ReceiptOutcome::class)]
    private ReceiptOutcome $outcome;

    /** Set after construction: the envelope AAD needs the id, which is minted here. */
    #[ORM\Column(length: 255, unique: true)]
    private string $storageKey = '';

    /** SHA-384 of the sealed PDF itself, hex. */
    #[ORM\Column(length: 96)]
    private string $contentHash;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column]
    private \DateTimeImmutable $sealedAt;

    /** Serial of the seal certificate used, so a reader can find the right key. */
    #[ORM\Column(length: 64)]
    private string $sealSerialNumber;

    public function __construct(
        Uuid $documentId,
        ReceiptSource $source,
        Uuid $sourceId,
        string $documentTitle,
        string $documentHash,
        ReceiptOutcome $outcome,
        string $contentHash,
        int $sizeBytes,
        \DateTimeImmutable $sealedAt,
        string $sealSerialNumber,
    ) {
        $this->initUuid();
        $this->documentId = $documentId;
        $this->source = $source;
        $this->sourceId = $sourceId;
        $this->documentTitle = $documentTitle;
        $this->documentHash = $documentHash;
        $this->outcome = $outcome;
        $this->contentHash = $contentHash;
        $this->sizeBytes = $sizeBytes;
        $this->sealedAt = $sealedAt;
        $this->sealSerialNumber = $sealSerialNumber;
    }

    public function getDocumentId(): Uuid
    {
        return $this->documentId;
    }

    public function getSource(): ReceiptSource
    {
        return $this->source;
    }

    public function getSourceId(): Uuid
    {
        return $this->sourceId;
    }

    public function getDocumentTitle(): string
    {
        return $this->documentTitle;
    }

    public function getDocumentHash(): string
    {
        return $this->documentHash;
    }

    public function getOutcome(): ReceiptOutcome
    {
        return $this->outcome;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function setStorageKey(string $storageKey): void
    {
        $this->storageKey = $storageKey;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getSealedAt(): \DateTimeImmutable
    {
        return $this->sealedAt;
    }

    public function getSealSerialNumber(): string
    {
        return $this->sealSerialNumber;
    }

    /** Filename offered on download. */
    public function getFilename(): string
    {
        return sprintf('receipt-%s.pdf', substr($this->getId()->toRfc4122(), 0, 8));
    }

    /** AAD binding the envelope to this receipt (ADR-006). */
    public function dekAad(): string
    {
        return 'receipt-dek:'.$this->getId()->toRfc4122();
    }
}
