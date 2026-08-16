<?php

declare(strict_types=1);

namespace App\Document\Repository;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentKeyGrant;
use App\Document\Entity\DocumentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Document>
 */
final class DocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Document::class);
    }

    /**
     * @return list<Document>
     */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.owner = :owner')
            ->setParameter('owner', $owner)
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every document $user can open: the ones they own, plus the ones a
     * DocumentKeyGrant lets them decrypt. One list, because the documents page
     * is a library - which of the two a row is shows up as its Role column.
     *
     * @return list<Document>
     */
    public function findVisibleTo(User $user): array
    {
        // LEFT JOINs plus an OR rather than a UNION: Doctrine has no UNION, and
        // grouping on the PK dedupes the row-per-granted-version fan-out.
        return $this->createQueryBuilder('d')
            ->leftJoin(DocumentVersion::class, 'v', Join::WITH, 'v.document = d')
            ->leftJoin(DocumentKeyGrant::class, 'g', Join::WITH, 'g.version = v AND g.user = :user')
            ->andWhere('d.owner = :user OR g.id IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('d.id')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Documents someone else owns that $user can decrypt - i.e. holds a
     * DocumentKeyGrant on at least one version of. The grants are the access
     * list (ADR-004), so this asks them directly rather than a sharing table.
     *
     * @return list<Document>
     */
    public function findSharedWith(User $user): array
    {
        // GROUP BY the PK, not SELECT DISTINCT: one grant per version means the
        // join yields a row per shared version, and deduping a Document row is
        // cheaper and safer done on its id.
        return $this->createQueryBuilder('d')
            ->join(DocumentVersion::class, 'v', Join::WITH, 'v.document = d')
            ->join(DocumentKeyGrant::class, 'g', Join::WITH, 'g.version = v AND g.user = :user')
            ->andWhere('d.owner != :user')
            ->setParameter('user', $user)
            ->groupBy('d.id')
            ->orderBy('d.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
