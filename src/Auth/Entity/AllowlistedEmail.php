<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Repository\AllowlistedEmailRepository;
use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An address allowed to register. Registration is invitation-only: an email
 * that is not on this list never gets an account. Stored lower-cased.
 */
#[ORM\Entity(repositoryClass: AllowlistedEmailRepository::class)]
#[ORM\Table(name: 'registration_allowlist')]
#[ORM\HasLifecycleCallbacks]
class AllowlistedEmail
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    /** Plain UUID, not an FK (like AuditLogEntry::$actorId); null = added from the console or seeded. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $addedBy;

    public function __construct(string $email, ?Uuid $addedBy = null)
    {
        $this->email = self::normalize($email);
        $this->addedBy = $addedBy;
    }

    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAddedBy(): ?Uuid
    {
        return $this->addedBy;
    }
}
