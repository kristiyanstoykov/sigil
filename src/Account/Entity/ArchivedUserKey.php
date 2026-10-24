<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Repository\ArchivedUserKeyRepository;
use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A deleted user's KEK, re-wrapped under the archive key instead of the root
 * key (ADR-015 §2). Every grant under that KEK is inert until a restore moves
 * it back, or the retention sweep deletes this row and the KEK with it.
 */
#[ORM\Entity(repositoryClass: ArchivedUserKeyRepository::class)]
#[ORM\Table(name: 'archived_user_key')]
#[ORM\Index(name: 'idx_archived_user_key_retain_until', columns: ['retain_until'])]
#[ORM\HasLifecycleCallbacks]
class ArchivedUserKey
{
    use HasUuid;
    use HasTimestamps;

    public const RETENTION_YEARS = 10;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private User $user;

    /** base64 of the archive-key-wrapped KEK envelope. Never a raw key. */
    #[ORM\Column(type: 'text')]
    private string $wrappedKek;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $retainUntil;

    public function __construct(User $user, string $wrappedKek, \DateTimeImmutable $deletedAt)
    {
        $this->initUuid();
        $this->user = $user;
        $this->wrappedKek = $wrappedKek;
        $this->retainUntil = $deletedAt->modify('+'.self::RETENTION_YEARS.' years');
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWrappedKek(): string
    {
        return $this->wrappedKek;
    }

    public function getRetainUntil(): \DateTimeImmutable
    {
        return $this->retainUntil;
    }

    public function isPastRetention(\DateTimeImmutable $now): bool
    {
        return $now >= $this->retainUntil;
    }
}
