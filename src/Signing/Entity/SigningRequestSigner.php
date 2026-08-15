<?php

declare(strict_types=1);

namespace App\Signing\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\Document\Entity\DocumentVersion;
use Doctrine\ORM\Mapping as ORM;

/**
 * One signer's place in a request's ordered list. $position is 1-based and
 * decides the turn order; $signedAt plus $version record what they produced.
 */
#[ORM\Entity]
#[ORM\Table(name: 'signing_request_signer')]
#[ORM\UniqueConstraint(name: 'uniq_signer_position', columns: ['request_id', 'position'])]
#[ORM\UniqueConstraint(name: 'uniq_signer_user', columns: ['request_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class SigningRequestSigner
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: SigningRequest::class, inversedBy: 'signers')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SigningRequest $request;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    /** The signed version this signer produced. Null until they sign. */
    #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DocumentVersion $version = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $declinedAt = null;

    /** Why they refused. Optional - a refusal needs no justification. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $declineReason = null;

    public function __construct(SigningRequest $request, User $user, int $position)
    {
        $this->initUuid();
        $this->request = $request;
        $this->user = $user;
        $this->position = $position;
        $request->addSigner($this);
    }

    public function getRequest(): SigningRequest
    {
        return $this->request;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getSignedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function getVersion(): ?DocumentVersion
    {
        return $this->version;
    }

    public function hasSigned(): bool
    {
        return null !== $this->signedAt;
    }

    public function markSigned(DocumentVersion $version, \DateTimeImmutable $at): void
    {
        $this->signedAt = $at;
        $this->version = $version;
    }

    public function getDeclinedAt(): ?\DateTimeImmutable
    {
        return $this->declinedAt;
    }

    public function getDeclineReason(): ?string
    {
        return $this->declineReason;
    }

    public function hasDeclined(): bool
    {
        return null !== $this->declinedAt;
    }

    public function markDeclined(?string $reason, \DateTimeImmutable $at): void
    {
        $this->declinedAt = $at;
        $this->declineReason = ('' === trim((string) $reason)) ? null : trim((string) $reason);
    }

    public function isUser(User $user): bool
    {
        return $this->user->getId()->toRfc4122() === $user->getId()->toRfc4122();
    }
}
