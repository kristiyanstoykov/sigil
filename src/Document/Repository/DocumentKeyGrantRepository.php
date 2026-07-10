<?php

declare(strict_types=1);

namespace App\Document\Repository;

use App\Core\Entity\User;
use App\Document\Entity\DocumentKeyGrant;
use App\Document\Entity\DocumentVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
