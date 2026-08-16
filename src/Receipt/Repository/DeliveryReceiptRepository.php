<?php

declare(strict_types=1);

namespace App\Receipt\Repository;

use App\Core\Entity\User;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Entity\DeliveryReceiptKeyGrant;
use App\Receipt\Enum\ReceiptSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DeliveryReceipt>
 */
final class DeliveryReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryReceipt::class);
    }

    /** The receipt for one signature request or one delivery, if it was sealed. */
    public function findForSource(ReceiptSource $source, Uuid $sourceId): ?DeliveryReceipt
    {
        return $this->findOneBy(['source' => $source, 'sourceId' => $sourceId]);
    }

    /**
     * Receipts for one document, newest first. A document can accumulate several
     * over its life: one per signature request that ran on it.
     *
     * @return list<DeliveryReceipt>
     */
    public function findForDocument(Uuid $documentId): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.documentId = :documentId')
            ->setParameter('documentId', $documentId, 'uuid')
            ->orderBy('r.sealedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every receipt $user may read - the grants are the access list, exactly as
     * they are for documents (ADR-004).
     *
     * @return list<DeliveryReceipt>
     */
    public function findReadableBy(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->join(DeliveryReceiptKeyGrant::class, 'g', Join::WITH, 'g.receipt = r AND g.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.sealedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
