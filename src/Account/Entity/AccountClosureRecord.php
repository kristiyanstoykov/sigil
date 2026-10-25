<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Repository\AccountClosureRecordRepository;
use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * The sealed record of one account deletion (ADR-015 §3). Its DEK is wrapped
 * under the user's KEK before that KEK is archived, so it is unreadable while
 * the account is deleted and comes back with it on restore.
 */
#[ORM\Entity(repositoryClass: AccountClosureRecordRepository::class)]
#[ORM\Table(name: 'account_closure_record')]
#[ORM\HasLifecycleCallbacks]
class AccountClosureRecord
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 255, unique: true)]
    private string $storageKey = '';

    /** base64 of the DEK wrapped under the user's KEK. Never a raw key. */
    #[ORM\Column(type: 'text')]
    private string $wrappedDek = '';

    /** Self-describing keyed fingerprint of the sealed PDF (ContentHasher). */
    #[ORM\Column(length: 128)]
    private string $contentHash;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column]
    private \DateTimeImmutable $sealedAt;

    #[ORM\Column(length: 64)]
    private string $sealSerialNumber;

    public function __construct(User $user, string $contentHash, int $sizeBytes, \DateTimeImmutable $sealedAt, string $sealSerialNumber)
    {
        $this->initUuid();
        $this->user = $user;
        $this->contentHash = $contentHash;
        $this->sizeBytes = $sizeBytes;
        $this->sealedAt = $sealedAt;
        $this->sealSerialNumber = $sealSerialNumber;
    }

    public function getUser(): User
    {
        return $this->user;
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

    public function getFilename(): string
    {
        return sprintf('account-closure-%s.pdf', $this->sealedAt->format('Y-m-d'));
    }

    /** AAD binding the envelope to this record (ADR-006). */
    public function dekAad(): string
    {
        return 'closure-record-dek:'.$this->getId()->toRfc4122();
    }
}
