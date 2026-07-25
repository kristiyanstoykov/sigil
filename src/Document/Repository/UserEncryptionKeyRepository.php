<?php

declare(strict_types=1);

namespace App\Document\Repository;

use App\Core\Entity\User;
use App\Document\Entity\UserEncryptionKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<UserEncryptionKey>
 */
final class UserEncryptionKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserEncryptionKey::class);
    }

    public function findForUser(User $user): ?UserEncryptionKey
    {
        return $this->findOneBy(['user' => $user]);
    }

    /**
     * Insert a user's wrapped KEK only if one does not already exist. Atomic:
     * a concurrent first-use race resolves to whichever request inserts first
     * (ON CONFLICT DO NOTHING), so a user can never end up with two KEKs.
     */
    public function insertIfAbsent(User $user, string $wrappedKek): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO user_encryption_key (id, user_id, wrapped_kek, created_at)
                VALUES (:id, :user, :kek, :now)
                ON CONFLICT (user_id) DO NOTHING
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'user' => $user->getId()->toRfc4122(),
                'kek' => $wrappedKek,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * Replace a row's wrapped KEK in place. Used only by the ADR-010 root-key
     * migration to re-wrap the same KEK under the token; the KEK itself is
     * unchanged, so every DEK grant stays valid. The entity is otherwise
     * immutable, so this goes straight to SQL by id.
     */
    public function updateWrappedKek(UserEncryptionKey $key, string $wrappedKek): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE user_encryption_key SET wrapped_kek = :kek WHERE id = :id',
            [
                'kek' => $wrappedKek,
                'id' => $key->getId()->toRfc4122(),
            ],
        );
    }
}
