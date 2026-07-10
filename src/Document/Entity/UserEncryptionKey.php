<?php

declare(strict_types=1);

namespace App\Document\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\Document\Repository\UserEncryptionKeyRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's Key-Encryption-Key (KEK), the middle layer of the envelope
 * (ADR-004). Created once per user. It wraps that user's per-file DEKs and is
 * itself stored here ONLY wrapped by the application root key.
 *
 * Deleting this row crypto-shreds the user: every DEK wrapped under this KEK
 * becomes unrecoverable, giving clean GDPR erasure without touching ciphertext.
 *
 * The column holds the base64 of a self-describing encryption envelope - never
 * a raw key.
 */
#[ORM\Entity(repositoryClass: UserEncryptionKeyRepository::class)]
#[ORM\Table(name: 'user_encryption_key')]
#[ORM\HasLifecycleCallbacks]
class UserEncryptionKey
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private User $user;

    /** base64 of the root-key-wrapped KEK envelope. Never a raw key. */
    #[ORM\Column(type: 'text')]
    private string $wrappedKek;

    public function __construct(User $user, string $wrappedKek)
    {
        $this->initUuid();
        $this->user = $user;
        $this->wrappedKek = $wrappedKek;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWrappedKek(): string
    {
        return $this->wrappedKek;
    }
}
