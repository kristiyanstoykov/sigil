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
 * @extends ServiceEntityRepository<DocumentKeyGrant>
 */
final class DocumentKeyGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DocumentKeyGrant::class);
    }

    public function findForVersionAndUser(DocumentVersion $version, User $user): ?DocumentKeyGrant
    {
        return $this->findOneBy(['version' => $version, 'user' => $user]);
    }

    /**
     * Everyone who can decrypt this version. Used when minting the next version:
     * access carries forward, so a signature added later does not silently lock
     * out whoever the document was shared with.
     *
     * @return list<User>
     */
    public function findUsersForVersion(DocumentVersion $version): array
    {
        // Rooted on User, not on the grant: DQL cannot select a joined entity
        // without also selecting the query's root alias.
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->join(DocumentKeyGrant::class, 'g', Join::WITH, 'g.user = u AND g.version = :version')
            ->setParameter('version', $version)
            ->getQuery()
            ->getResult();
    }

    /**
     * Who a document is shared with - everyone holding a grant on any of its
     * versions, minus the owner. The grants ARE the access list (ADR-004);
     * there is no separate sharing table to drift out of sync with them.
     *
     * @return list<User>
     */
    public function findRecipientsForDocument(Document $document): array
    {
        // GROUP BY the primary key rather than SELECT DISTINCT: a user holds one
        // grant per version, so the join duplicates them - but PostgreSQL cannot
        // DISTINCT a row containing User::$roles, which is a json column with no
        // equality operator. Grouping by the PK dedupes without comparing it.
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->join(DocumentKeyGrant::class, 'g', Join::WITH, 'g.user = u')
            ->join(DocumentVersion::class, 'v', Join::WITH, 'g.version = v')
            ->andWhere('v.document = :document')
            ->andWhere('u != :owner')
            ->setParameter('document', $document)
            ->setParameter('owner', $document->getOwner())
            ->groupBy('u.id')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Whether $user can decrypt at least one version of $document. */
    public function hasGrantForDocument(Document $document, User $user): bool
    {
        $count = $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->join('g.version', 'v')
            ->andWhere('v.document = :document')
            ->andWhere('g.user = :user')
            ->setParameter('document', $document)
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * Every grant on any version of a document, whoever holds it. What the
     * eraser has to destroy alongside the document.
     *
     * @return list<DocumentKeyGrant>
     */
    public function findForDocument(Document $document): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.version', 'v')
            ->andWhere('v.document = :document')
            ->setParameter('document', $document)
            ->getQuery()
            ->getResult();
    }

    /**
     * Revoke = delete the grant rows. The ciphertext is untouched and no key is
     * re-generated: without a grant there is no wrapped DEK for this user, so
     * there is nothing left to decrypt with.
     *
     * @return int rows deleted
     */
    public function deleteForDocumentAndUser(Document $document, User $user): int
    {
        $grants = $this->createQueryBuilder('g')
            ->join('g.version', 'v')
            ->andWhere('v.document = :document')
            ->andWhere('g.user = :user')
            ->setParameter('document', $document)
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $em = $this->getEntityManager();
        foreach ($grants as $grant) {
            $em->remove($grant);
        }
        $em->flush();

        return \count($grants);
    }
}
