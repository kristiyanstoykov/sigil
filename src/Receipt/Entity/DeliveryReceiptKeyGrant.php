<?php

declare(strict_types=1);

namespace App\Receipt\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\Receipt\Repository\DeliveryReceiptKeyGrantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One participant's wrapped copy of a receipt's DEK - the same three-layer
 * envelope the documents use (ADR-004), and the receipt's access list.
 *
 * Unlike a {@see \App\Document\Entity\DocumentKeyGrant} these are never added or
 * revoked after the fact: a receipt's audience is the requester plus the signers
 * as they stood when it was sealed, and that set is part of what it attests.
 */
#[ORM\Entity(repositoryClass: DeliveryReceiptKeyGrantRepository::class)]
#[ORM\Table(name: 'delivery_receipt_key_grant')]
#[ORM\UniqueConstraint(name: 'uniq_receipt_grant_receipt_user', columns: ['receipt_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class DeliveryReceiptKeyGrant
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: DeliveryReceipt::class)]
    #[ORM\JoinColumn(name: 'receipt_id', nullable: false, onDelete: 'CASCADE')]
    private DeliveryReceipt $receipt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private User $user;

    /** The receipt's DEK, wrapped under this user's KEK. */
    #[ORM\Column(type: 'text')]
    private string $wrappedDek;

    public function __construct(DeliveryReceipt $receipt, User $user, string $wrappedDek)
    {
        $this->initUuid();
        $this->receipt = $receipt;
        $this->user = $user;
        $this->wrappedDek = $wrappedDek;
    }

    public function getReceipt(): DeliveryReceipt
    {
        return $this->receipt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWrappedDek(): string
    {
        return $this->wrappedDek;
    }
}
