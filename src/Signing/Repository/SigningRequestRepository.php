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

    /**
     * The most recent request on a document, whatever its state. Also the guard
     * for "one request per document, ever": a non-null answer blocks a new one.
     */
    public function findLatestForDocument(Document $document): ?SigningRequest
    {
        return $this->findOneBy(['document' => $document], ['createdAt' => 'DESC']);
    }

    /**
     * Every request that ever ran on a document, oldest first.
     *
     * @return list<SigningRequest>
     */
    public function findAllForDocument(Document $document): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.document = :document')
            ->setParameter('document', $document)
            ->orderBy('r.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
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
     * Closed requests $user took part in, either role, newest first - the
     * History tab. Includes the ones they declined, which is the only place
     * those survive: closing revokes the decliner's document grant, so the
     * document itself is gone from every list they can see.
     *
     * @return list<SigningRequest>
     */
    public function findClosedForParticipant(User $user): array
    {
        // GROUP BY the PK rather than DISTINCT: the signers join yields one row
        // per signer, and deduping on the id is cheaper and order-safe.
        return $this->createQueryBuilder('r')
            ->leftJoin('r.signers', 's')
            ->andWhere('r.status != :pending')
            ->andWhere('r.requester = :user OR s.user = :user')
            ->setParameter('user', $user)
            ->setParameter('pending', SigningRequestStatus::Pending)
            ->groupBy('r.id')
            ->orderBy('r.closedAt', 'DESC')
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
