<?php

declare(strict_types=1);

namespace App\Signing\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Signing\Enum\SigningRequestStatus;
use App\Signing\Repository\SigningRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A request for one or more people to sign a document, in the order they were
 * listed: signer k can only sign once k-1 has (ADR-007, the human-wait async).
 * Each signature is a new DocumentVersion; this entity only tracks whose turn
 * it is and when the whole thing runs out of time.
 */
#[ORM\Entity(repositoryClass: SigningRequestRepository::class)]
#[ORM\Table(name: 'signing_request')]
#[ORM\Index(name: 'idx_signing_request_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class SigningRequest
{
    use HasUuid;
    use HasTimestamps;

    /** Deadline policy: the default the form offers, and the ceiling it enforces. */
    public const int DEFAULT_DEADLINE_DAYS = 7;
    public const int MAX_DEADLINE_DAYS = 30;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $requester;

    #[ORM\Column(enumType: SigningRequestStatus::class)]
    private SigningRequestStatus $status = SigningRequestStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $deadline;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /**
     * @var Collection<int, SigningRequestSigner>
     */
    #[ORM\OneToMany(mappedBy: 'request', targetEntity: SigningRequestSigner::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $signers;

    public function __construct(Document $document, User $requester, \DateTimeImmutable $deadline)
    {
        $this->initUuid();
        $this->document = $document;
        $this->requester = $requester;
        $this->deadline = $deadline;
        $this->signers = new ArrayCollection();
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getRequester(): User
    {
        return $this->requester;
    }

    public function getStatus(): SigningRequestStatus
    {
        return $this->status;
    }

    public function getDeadline(): \DateTimeImmutable
    {
        return $this->deadline;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /**
     * @return Collection<int, SigningRequestSigner>
     */
    public function getSigners(): Collection
    {
        return $this->signers;
    }

    public function addSigner(SigningRequestSigner $signer): void
    {
        if (!$this->signers->contains($signer)) {
            $this->signers->add($signer);
        }
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    /** Whose turn it is: the first signer in position order who has not signed. */
    public function currentSigner(): ?SigningRequestSigner
    {
        foreach ($this->orderedSigners() as $signer) {
            if (!$signer->hasSigned()) {
                return $signer;
            }
        }

        return null;
    }

    public function signerFor(User $user): ?SigningRequestSigner
    {
        foreach ($this->signers as $signer) {
            if ($signer->isUser($user)) {
                return $signer;
            }
        }

        return null;
    }

    /** Whether it is $user's turn to sign right now. */
    public function isTurnOf(User $user): bool
    {
        $current = $this->currentSigner();

        return $this->isPending() && null !== $current && $current->isUser($user);
    }

    public function signedCount(): int
    {
        $count = 0;
        foreach ($this->signers as $signer) {
            if ($signer->hasSigned()) {
                ++$count;
            }
        }

        return $count;
    }

    /** The signer who refused, if this request was closed by a refusal. */
    public function declinedBy(): ?SigningRequestSigner
    {
        foreach ($this->signers as $signer) {
            if ($signer->hasDeclined()) {
                return $signer;
            }
        }

        return null;
    }

    public function hasAnySignature(): bool
    {
        return $this->signedCount() > 0;
    }

    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return $now > $this->deadline;
    }

    /**
     * The signers in turn order. The OrderBy above covers a request loaded from
     * the database, but not one still in memory from the request that created it.
     *
     * @return list<SigningRequestSigner>
     */
    public function orderedSigners(): array
    {
        $signers = array_values($this->signers->toArray());
        usort($signers, static fn (SigningRequestSigner $a, SigningRequestSigner $b): int => $a->getPosition() <=> $b->getPosition());

        return $signers;
    }

    public function close(SigningRequestStatus $status, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->closedAt = $at;
    }
}
