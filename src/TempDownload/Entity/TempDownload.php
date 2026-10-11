<?php

declare(strict_types=1);

namespace App\TempDownload\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\TempDownload\Repository\TempDownloadRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A file handed to its owner once (ADR-015 §8). Encrypted like a document - own
 * DEK, wrapped under the owner's KEK - but with a single reader the wrap lives on
 * the row instead of in a grant table.
 *
 * The id names the file; it never grants it: every download checks the owner.
 */
#[ORM\Entity(repositoryClass: TempDownloadRepository::class)]
#[ORM\Table(name: 'temp_download')]
#[ORM\Index(name: 'idx_temp_download_owner_group', columns: ['owner_id', 'group_id'])]
#[ORM\Index(name: 'idx_temp_download_expires', columns: ['expires_at'])]
#[ORM\HasLifecycleCallbacks]
class TempDownload
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** Ties the parts of one producer's output together, e.g. one data export. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $groupId;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 100)]
    private string $mimeType;

    #[ORM\Column]
    private int $sizeBytes;

    /** Empty until stored; cleared once the ciphertext is gone. */
    #[ORM\Column(length: 255)]
    private string $storageKey = '';

    /** base64 of the DEK wrapped under the owner's KEK. Never a raw key. */
    #[ORM\Column(type: 'text')]
    private string $wrappedDek = '';

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    public function __construct(
        User $owner,
        string $filename,
        string $mimeType,
        int $sizeBytes,
        \DateTimeImmutable $expiresAt,
        ?Uuid $groupId = null,
    ) {
        $this->initUuid();
        $this->owner = $owner;
        $this->filename = $filename;
        $this->mimeType = $mimeType;
        $this->sizeBytes = $sizeBytes;
        $this->expiresAt = $expiresAt;
        $this->groupId = $groupId;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getGroupId(): ?Uuid
    {
        return $this->groupId;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getWrappedDek(): string
    {
        return $this->wrappedDek;
    }

    public function stored(string $storageKey, string $wrappedDek): void
    {
        $this->storageKey = $storageKey;
        $this->wrappedDek = $wrappedDek;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function isConsumed(): bool
    {
        return null !== $this->consumedAt;
    }

    /** Whether the one download is still to be had at $now. */
    public function isAvailable(\DateTimeImmutable $now): bool
    {
        return null === $this->consumedAt && $this->expiresAt > $now;
    }

    /** Envelope AAD: binds the ciphertext and the DEK wrap to this row. */
    public function dekAad(): string
    {
        return 'temp-download:'.$this->getId()->toRfc4122();
    }
}
