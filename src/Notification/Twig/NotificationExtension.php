<?php

declare(strict_types=1);

namespace App\Notification\Twig;

use App\Core\Security\CurrentUser;
use App\Notification\Entity\Notification;
use App\Notification\Form\MarkAllReadFormFactory;
use App\Notification\Form\OpenNotificationFormFactory;
use App\Notification\Repository\NotificationRepository;
use App\Notification\Service\InboxTopic;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The header bell, exposed to the authenticated layout.
 *
 * Same seam and the same reason as document_upload_form(): the bell renders on
 * every page, so threading its contents through every controller would be
 * absurd. Both reads are memoised per request - the layout asks for the count
 * and the list separately.
 */
final class NotificationExtension extends AbstractExtension
{
    /** @var list<Notification>|null */
    private ?array $recent = null;

    private ?int $unread = null;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly OpenNotificationFormFactory $openForms,
        private readonly MarkAllReadFormFactory $markAllForms,
        private readonly CurrentUser $currentUser,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications', $this->unreadCount(...)),
            new TwigFunction('recent_notifications', $this->recentNotifications(...)),
            new TwigFunction('notification_open_form', $this->openForm(...)),
            new TwigFunction('notification_topic', $this->topic(...)),
            new TwigFunction('notification_mark_all_form', $this->markAllForm(...)),
        ];
    }

    public function unreadCount(): int
    {
        $user = $this->currentUser->getOrNull();
        if (null === $user) {
            return 0;
        }

        return $this->unread ??= $this->notifications->countUnreadFor($user);
    }

    /**
     * @return list<Notification>
     */
    public function recentNotifications(int $limit = 8): array
    {
        $user = $this->currentUser->getOrNull();
        if (null === $user) {
            return [];
        }

        return $this->recent ??= $this->notifications->findRecentFor($user, $limit);
    }

    public function openForm(Notification $notification): FormView
    {
        return $this->openForms->create($notification)->createView();
    }

    /** The bell renders on every page, so this comes through the seam too. */
    public function markAllForm(): FormView
    {
        return $this->markAllForms->create()->createView();
    }

    /**
     * The Mercure topic the bell listens on - the reader's own inbox, and null
     * for anyone the layout renders without a user.
     */
    public function topic(): ?string
    {
        $user = $this->currentUser->getOrNull();

        return null !== $user ? InboxTopic::for($user) : null;
    }
}
