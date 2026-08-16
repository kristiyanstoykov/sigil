<?php

declare(strict_types=1);

namespace App\Delivery\Repository;

use App\Core\Entity\User;
use App\Delivery\Entity\Delivery;
use App\Document\Entity\Document;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Delivery>
 */
final class DeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Delivery::class);
    }

    /**
     * Every delivery made of this document, newest first. A document can be
     * served any number of times - unlike a signature request, which happens once.
     *
     * @return list<Delivery>
     */
    public function findForDocument(Document $document): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.document = :document')
            ->setParameter('document', $document)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Deliveries $user was served, newest first.
     *
     * @return list<Delivery>
     */
    public function findServedTo(User $user): array
    {
        return $this->createQueryBuilder('d')
            ->join('d.recipients', 'r')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Whether $user was served this document - what makes their Role "Recipient". */
    public function wasServed(Document $document, User $user): bool
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(r.id)')
            ->join('d.recipients', 'r')
            ->andWhere('d.document = :document')
            ->andWhere('r.user = :user')
            ->setParameter('document', $document)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
