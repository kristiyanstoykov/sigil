<?php

declare(strict_types=1);

namespace App\Receipt\Repository;

use App\Core\Entity\User;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Entity\DeliveryReceiptKeyGrant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryReceiptKeyGrant>
 */
final class DeliveryReceiptKeyGrantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryReceiptKeyGrant::class);
    }

    public function findForReceiptAndUser(DeliveryReceipt $receipt, User $user): ?DeliveryReceiptKeyGrant
    {
        return $this->findOneBy(['receipt' => $receipt, 'user' => $user]);
    }
}
