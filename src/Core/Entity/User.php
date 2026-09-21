<?php

declare(strict_types=1);

namespace App\Core\Entity;

use App\Core\Repository\UserRepository;
use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Google\TwoFactorInterface;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['email'], message: 'An account with this email already exists.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    use HasUuid;
    use HasTimestamps;

    /** @var non-empty-string */
    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 100)]
    private string $firstName;

    #[ORM\Column(length: 100)]
    private string $lastName;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $company = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $position = null;

    #[ORM\Column(nullable: true)]
    private ?string $googleAuthenticatorSecret = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $totpEnabled = false;

    /**
     * RFC 6238 §5.2 replay guard: the last code accepted and when. A code is
     * good for one login; the same six digits presented again inside the
     * verification window are refused even though the algorithm would accept
     * them. The value is spent by the time it is stored.
     */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $lastTotpCode = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastTotpUsedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isVerified = false;

    /**
     * What goes into the session. The password hash is replaced by its crc32c
     * (Symfony compares that to invalidate sessions on a password change) and
     * the TOTP seed is dropped: every request refreshes the user from the
     * database, which is where the bundle reads it - the session copy would
     * only ever be a second place for it to leak from.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = hash('crc32c', $this->password);
        $data["\0".self::class."\0googleAuthenticatorSecret"] = null;

        return $data;
    }

    // ── UserInterface ────────────────────────────────────────────────────────

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void {}

    // ── PasswordAuthenticatedUserInterface ───────────────────────────────────

    public function getPassword(): string
    {
        return $this->password;
    }

    // ── TwoFactorInterface (Google Authenticator) ────────────────────────────

    public function isGoogleAuthenticatorEnabled(): bool
    {
        return $this->totpEnabled && $this->googleAuthenticatorSecret !== null;
    }

    public function getGoogleAuthenticatorUsername(): string
    {
        return $this->email;
    }

    /** Whether this exact code was already accepted within the last $windowSeconds. */
    public function wasTotpCodeUsed(string $code, \DateTimeImmutable $now, int $windowSeconds = 90): bool
    {
        return null !== $this->lastTotpCode
            && null !== $this->lastTotpUsedAt
            && hash_equals($this->lastTotpCode, $code)
            && $now->getTimestamp() - $this->lastTotpUsedAt->getTimestamp() < $windowSeconds;
    }

    public function recordTotpCode(string $code, \DateTimeImmutable $now): void
    {
        $this->lastTotpCode = $code;
        $this->lastTotpUsedAt = $now;
    }

    public function getGoogleAuthenticatorSecret(): ?string
    {
        return $this->googleAuthenticatorSecret;
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    /**
     * Identity, not object identity: two managed instances of the same row
     * (a fresh load beside a session-refreshed user) must compare equal.
     */
    public function is(User $other): bool
    {
        return $this->getId()->equals($other->getId());
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /** @param non-empty-string $email */
    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getCompany(): ?string
    {
        return $this->company;
    }

    public function setCompany(?string $company): static
    {
        $this->company = $company;

        return $this;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    public function setPosition(?string $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function setGoogleAuthenticatorSecret(?string $secret): static
    {
        $this->googleAuthenticatorSecret = $secret;

        return $this;
    }

    public function isTotpEnabled(): bool
    {
        return $this->totpEnabled;
    }

    public function enableTotp(): static
    {
        $this->totpEnabled = true;

        return $this;
    }

    public function disableTotp(): static
    {
        $this->totpEnabled = false;
        $this->googleAuthenticatorSecret = null;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }
}
