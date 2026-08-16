<?php

declare(strict_types=1);

namespace App\Delivery\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\Delivery\Repository\DeliveryRepository;
use App\Document\Entity\Document;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Serving a document on people: registered mail, not a request. Nothing is asked
 * of the recipients, they cannot refuse it, and the sender gets a sealed receipt
 * proving it reached them (eIDAS Art. 43-44; see ADR-012).
 *
 * Unlike a {@see \App\Signing\Entity\SigningRequest} this has no order, no turn
 * and no deadline - everyone is served at once, and the delivery is finished the
 * moment it is made. A document may be delivered any number of times, to
 * different people, and independently of whether it was ever sent for signature.
 */
#[ORM\Entity(repositoryClass: DeliveryRepository::class)]
#[ORM\Table(name: 'delivery')]
#[ORM\HasLifecycleCallbacks]
class Delivery
{
    use HasUuid;
    use HasTimestamps;

    /** An optional covering note - what this is and why you are getting it. */
    public const int MAX_NOTE_LENGTH = 500;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Document $document;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $sender;

    #[ORM\Column(length: self::MAX_NOTE_LENGTH, nullable: true)]
    private ?string $note = null;

    /**
     * @var Collection<int, DeliveryRecipient>
     */
    #[ORM\OneToMany(mappedBy: 'delivery', targetEntity: DeliveryRecipient::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $recipients;

    public function __construct(Document $document, User $sender, ?string $note = null)
    {
        $this->initUuid();
        $this->document = $document;
        $this->sender = $sender;
        $this->note = ('' === trim((string) $note)) ? null : trim((string) $note);
        $this->recipients = new ArrayCollection();
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getSender(): User
    {
        return $this->sender;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    /**
     * @return Collection<int, DeliveryRecipient>
     */
    public function getRecipients(): Collection
    {
        return $this->recipients;
    }

    public function addRecipient(DeliveryRecipient $recipient): void
    {
        if (!$this->recipients->contains($recipient)) {
            $this->recipients->add($recipient);
        }
    }

    public function hasRecipient(User $user): bool
    {
        foreach ($this->recipients as $recipient) {
            if ($recipient->isUser($user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sender plus everyone served - who the receipt is addressed to.
     *
     * @return list<User>
     */
    public function participants(): array
    {
        $participants = [$this->sender];
        foreach ($this->recipients as $recipient) {
            $participants[] = $recipient->getUser();
        }

        return $participants;
    }
}
