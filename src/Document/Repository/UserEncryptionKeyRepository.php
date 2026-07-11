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
}
