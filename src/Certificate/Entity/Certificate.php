<?php

declare(strict_types=1);

namespace App\Certificate\Entity;

use App\Certificate\Enum\CertificateDisplayStatus;
use App\Certificate\Enum\CertificateStatus;
use App\Certificate\Repository\CertificateRepository;
use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's X.509 signing certificate. Holds public material and the ADR-008
 * PIN gate state ONLY - the private key lives in a dedicated PKCS#11 token
 * (one token per certificate) and never leaves it (ADR-005).
 */
#[ORM\Entity(repositoryClass: CertificateRepository::class)]
#[ORM\Table(name: 'certificate')]
#[ORM\Index(columns: ['status'], name: 'idx_certificate_status')]
#[ORM\HasLifecycleCallbacks]
class Certificate
{
    use HasUuid;
    use HasTimestamps;

    public const int MAX_PER_USER = 3;
    public const int MAX_PIN_ATTEMPTS = 5;
    public const int PIN_WINDOW_SECONDS = 3600;
    public const int HOLD_HOURS = 72;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $serialNumber;

    #[ORM\Column(length: 255)]
    private string $subjectDn;

    /** Public certificate only - never any private-key material. */
    #[ORM\Column(type: 'text')]
    private string $certificatePem;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $notBefore;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $notAfter;

    /** Stable suite id from SignatureAlgorithmInterface::id(), e.g. "ECDSA-P384-SHA384/v1". */
    #[ORM\Column(length: 50)]
    private string $algorithmId;

    /** PKCS#11 token label; one token per certificate. */
    #[ORM\Column(length: 100, unique: true)]
    private string $tokenLabel;

    #[ORM\Column(length: 100)]
    private string $keyLabel;

    #[ORM\Column(length: 20, enumType: CertificateStatus::class)]
    private CertificateStatus $status = CertificateStatus::Active;

    // --- ADR-008 PIN gate: DB is the single live counter -------------------

    /** Argon2id hash of the token User PIN. The PIN itself is never stored. */
    #[ORM\Column(length: 255)]
    private string $pinHash;

    #[ORM\Column(type: 'smallint')]
    private int $failedPinAttempts = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastFailedPinAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lockedAt = null;

    /**
     * Owner-initiated temporary suspension (the CRL "certificateHold" idea):
     * while heldUntil is in the future the certificate cannot sign. Status
     * stays Active in the DB, so the hold lifts by itself once the moment
     * passes - no scheduler needed. Placing AND releasing a hold are
     * PIN-gated in the controller.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $heldUntil = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $revocationReason = null;

    public function __construct(
        User $user,
        string $serialNumber,
        string $subjectDn,
        string $certificatePem,
        \DateTimeImmutable $notBefore,
        \DateTimeImmutable $notAfter,
        string $algorithmId,
        string $tokenLabel,
        string $keyLabel,
        string $pinHash,
    ) {
        $this->user = $user;
        $this->serialNumber = $serialNumber;
        $this->subjectDn = $subjectDn;
        $this->certificatePem = $certificatePem;
        $this->notBefore = $notBefore;
        $this->notAfter = $notAfter;
        $this->algorithmId = $algorithmId;
        $this->tokenLabel = $tokenLabel;
        $this->keyLabel = $keyLabel;
        $this->pinHash = $pinHash;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSerialNumber(): string
    {
        return $this->serialNumber;
    }

    public function getSubjectDn(): string
    {
        return $this->subjectDn;
    }

    public function getCertificatePem(): string
    {
        return $this->certificatePem;
    }

    public function getNotBefore(): \DateTimeImmutable
    {
        return $this->notBefore;
    }

    public function getNotAfter(): \DateTimeImmutable
    {
        return $this->notAfter;
    }

    public function getAlgorithmId(): string
    {
        return $this->algorithmId;
    }

    public function getTokenLabel(): string
    {
        return $this->tokenLabel;
    }

    public function getKeyLabel(): string
    {
        return $this->keyLabel;
    }

    public function getStatus(): CertificateStatus
    {
        return $this->status;
    }

    public function getPinHash(): string
    {
        return $this->pinHash;
    }

    public function setPinHash(string $pinHash): void
    {
        $this->pinHash = $pinHash;
    }

    public function getFailedPinAttempts(): int
    {
        return $this->failedPinAttempts;
    }

    public function getLockedAt(): ?\DateTimeImmutable
    {
        return $this->lockedAt;
    }

    public function isLocked(): bool
    {
        return CertificateStatus::Locked === $this->status;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->isWithinValidity($now) && !$this->isOnHold($now);
    }

    /** Active and inside the validity window - ignores a hold (used to verify the PIN that lifts one). */
    public function isWithinValidity(\DateTimeImmutable $now): bool
    {
        return CertificateStatus::Active === $this->status
            && $now >= $this->notBefore
            && $now <= $this->notAfter;
    }

    public function isOnHold(\DateTimeInterface $now): bool
    {
        return null !== $this->heldUntil && $now < $this->heldUntil;
    }

    public function getHeldUntil(): ?\DateTimeImmutable
    {
        return $this->heldUntil;
    }

    public function hold(\DateTimeImmutable $until): void
    {
        $this->heldUntil = $until;
    }

    public function releaseHold(): void
    {
        $this->heldUntil = null;
    }

    /**
     * What the UI shows. Adds the virtual OnHold state (the DB status stays
     * Active while held) and computes Expired from the dates, since nothing
     * transitions the stored status at the expiry moment.
     */
    public function getDisplayStatus(?\DateTimeImmutable $now = null): CertificateDisplayStatus
    {
        $now ??= new \DateTimeImmutable();

        if (CertificateStatus::Active === $this->status) {
            if ($now > $this->notAfter) {
                return CertificateDisplayStatus::Expired;
            }
            if ($this->isOnHold($now)) {
                return CertificateDisplayStatus::OnHold;
            }
        }

        return CertificateDisplayStatus::fromStatus($this->status);
    }

    public function lock(\DateTimeImmutable $at): void
    {
        $this->status = CertificateStatus::Locked;
        $this->lockedAt = $at;
    }

    public function unlock(): void
    {
        $this->status = CertificateStatus::Active;
        $this->lockedAt = null;
        $this->failedPinAttempts = 0;
        $this->lastFailedPinAt = null;
    }

    public function resetPinCounter(): void
    {
        $this->failedPinAttempts = 0;
        $this->lastFailedPinAt = null;
    }

    public function revoke(\DateTimeImmutable $at, string $reason): void
    {
        $this->status = CertificateStatus::Revoked;
        $this->revokedAt = $at;
        $this->revocationReason = $reason;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getRevocationReason(): ?string
    {
        return $this->revocationReason;
    }
}
