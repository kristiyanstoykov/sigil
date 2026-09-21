<?php

declare(strict_types=1);

namespace App\Notification\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\Notification\Enum\NotificationType;
use App\Notification\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One line in one person's inbox.
 *
 * $readAt is an inbox artifact and nothing more. It belongs to the recipient, it
 * is never shown to the sender, never audited and never named in a receipt - so
 * it is not a read receipt and does not touch ADR-012, which records consignment
 * and deliberately not retrieval. A delivery is complete when the key grant and
 * this row exist; whether the recipient has since clicked the row says nothing
 * about it.
 *
 * $documentId is a plain UUID column, not an FK, like AuditLogEntry::$actorId:
 * an expired unsigned request has its document erased, and the notification that
 * announced it must survive that.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(name: 'idx_notification_inbox', columns: ['recipient_id', 'created_at'])]
#[ORM\Index(name: 'idx_notification_unread', columns: ['recipient_id', 'read_at'])]
#[ORM\HasLifecycleCallbacks]
class Notification
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $recipient;

    #[ORM\Column(length: 40, enumType: NotificationType::class)]
    private NotificationType $type;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $body;

    /** Resolved when the notification is made, so it survives what it points at. */
    #[ORM\Column(length: 512)]
    private string $url;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $documentId;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        User $recipient,
        NotificationType $type,
        string $title,
        string $url,
        ?string $body = null,
        ?Uuid $documentId = null,
    ) {
        $this->initUuid();
        $this->recipient = $recipient;
        $this->type = $type;
        $this->title = $title;
        $this->url = $url;
        $this->body = $body;
        $this->documentId = $documentId;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): ?string
    {
        return $this->body;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getDocumentId(): ?Uuid
    {
        return $this->documentId;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    /** Idempotent: reading something twice does not move the moment it was read. */
    public function markRead(\DateTimeImmutable $at): void
    {
        $this->readAt ??= $at;
    }

    public function isFor(User $user): bool
    {
        return $this->recipient->is($user);
    }
}
