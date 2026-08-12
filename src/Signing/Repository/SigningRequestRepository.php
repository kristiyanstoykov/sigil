<?php

declare(strict_types=1);

namespace App\Signing\Repository;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Signing\Entity\SigningRequest;
use App\Signing\Enum\SigningRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SigningRequest>
 */
final class SigningRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SigningRequest::class);
    }

    /** The one open request on a document, if any. At most one may be pending. */
    public function findPendingForDocument(Document $document): ?SigningRequest
    {
        return $this->findOneBy(
            ['document' => $document, 'status' => SigningRequestStatus::Pending],
        );
    }

    /** The most recent request on a document, whatever its state. */
    public function findLatestForDocument(Document $document): ?SigningRequest
    {
        return $this->findOneBy(['document' => $document], ['createdAt' => 'DESC']);
    }

    /**
     * Pending requests $user is listed on, newest first. Includes turns that are
     * not theirs yet; the caller decides what to show.
     *
     * @return list<SigningRequest>
     */
    public function findPendingForSigner(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->join('r.signers', 's')
            ->andWhere('s.user = :user')
            ->andWhere('r.status = :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', SigningRequestStatus::Pending)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Requests the requester sent that are still open, newest first.
     *
     * @return list<SigningRequest>
     */
    public function findPendingByRequester(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.requester = :user')
            ->andWhere('r.status = :pending')
            ->setParameter('user', $user)
            ->setParameter('pending', SigningRequestStatus::Pending)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Pending requests whose deadline has passed - the sweep's work list.
     *
     * @return list<SigningRequest>
     */
    public function findOverdue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :pending')
            ->andWhere('r.deadline < :now')
            ->setParameter('pending', SigningRequestStatus::Pending)
            ->setParameter('now', $now)
            ->orderBy('r.deadline', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
