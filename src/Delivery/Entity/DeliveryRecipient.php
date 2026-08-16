<?php

declare(strict_types=1);

namespace App\Delivery\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\Document\Entity\DocumentVersion;
use Doctrine\ORM\Mapping as ORM;

/**
 * One person served by a delivery, and the moment they were served.
 *
 * $deliveredAt is not nullable and is set when the delivery is made: Sigil
 * attests consignment - the act of making the content available - and tracks no
 * retrieval (ADR-012, Borica's model). Consignment therefore *is* delivery, and
 * the key grant minted for this recipient is the event it records.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delivery_recipient')]
#[ORM\UniqueConstraint(name: 'uniq_delivery_recipient', columns: ['delivery_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class DeliveryRecipient
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: Delivery::class, inversedBy: 'recipients')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Delivery $delivery;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $deliveredAt;

    /** The version served. Later versions are not delivered retroactively. */
    #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DocumentVersion $version;

    public function __construct(Delivery $delivery, User $user, DocumentVersion $version, \DateTimeImmutable $deliveredAt)
    {
        $this->initUuid();
        $this->delivery = $delivery;
        $this->user = $user;
        $this->version = $version;
        $this->deliveredAt = $deliveredAt;
        $delivery->addRecipient($this);
    }

    public function getDelivery(): Delivery
    {
        return $this->delivery;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDeliveredAt(): \DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getVersion(): DocumentVersion
    {
        return $this->version;
    }

    public function isUser(User $user): bool
    {
        return $this->user->getId()->toRfc4122() === $user->getId()->toRfc4122();
    }
}
