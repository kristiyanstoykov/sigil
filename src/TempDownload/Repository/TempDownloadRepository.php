<?php

declare(strict_types=1);

namespace App\TempDownload\Repository;

use App\Core\Entity\User;
use App\TempDownload\Entity\TempDownload;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TempDownload>
 */
final class TempDownloadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TempDownload::class);
    }

    /**
     * Take the one download. A single conditional UPDATE, so of two concurrent
     * requests exactly one sees a row change; the other gets false.
     */
    public function claim(TempDownload $download, \DateTimeImmutable $now): bool
    {
        $claimed = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE temp_download SET consumed_at = :now
              WHERE id = :id AND consumed_at IS NULL AND expires_at > :now',
            ['now' => $now, 'id' => $download->getId()->toRfc4122()],
            ['now' => Types::DATETIME_IMMUTABLE],
        );

        if (1 !== $claimed) {
            return false;
        }

        $this->getEntityManager()->refresh($download);

        return true;
    }

    /**
     * @return list<TempDownload>
     */
    public function findGroup(User $owner, Uuid $groupId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.owner = :owner')
            ->andWhere('t.groupId = :group')
            ->setParameter('owner', $owner)
            ->setParameter('group', $groupId, 'uuid')
            ->orderBy('t.filename', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything whose one download is spent or whose time is up.
     *
     * @return list<TempDownload>
     */
    public function findSweepable(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.consumedAt IS NOT NULL OR t.expiresAt <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
