<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Core\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;

/**
 * Puts a line in someone's inbox.
 *
 * Store first, push second. The stored row is the notification; a live push is
 * only how it arrives sooner. Push alone would lose every notification that
 * arrives while its recipient is offline, which is exactly the one that matters,
 * so the row is written before anything is broadcast and a broadcast failure
 * never undoes it.
 */
final class Notifier
{
    /**
     * What goes over the wire. Deliberately says nothing about what happened:
     * document titles stay in Postgres, and the browser answers this by
     * re-reading its own inbox over an authenticated request. So the hub - a
     * separate process holding a shared key, with its own logs and its own
     * message history - never sees the name of anybody's document.
     */
    private const string NUDGE = '{"event":"notification.created"}';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notify(
        User $recipient,
        NotificationType $type,
        string $title,
        string $url,
        ?string $body = null,
        ?Uuid $documentId = null,
    ): Notification {
        $notification = new Notification($recipient, $type, $title, $url, $body, $documentId);

        $this->em->persist($notification);
        $this->em->flush();

        $this->push($recipient);

        return $notification;
    }

    /**
     * Best effort by design: the row is already committed, so a hub that is
     * down, slow or absent costs the recipient nothing but immediacy.
     */
    private function push(User $recipient): void
    {
        try {
            $this->hub->publish(new Update(InboxTopic::for($recipient), self::NUDGE, private: true));
        } catch (\Throwable $e) {
            $this->logger->warning('Live notification push failed; the stored notification is unaffected.', [
                'recipient' => $recipient->getId()->toRfc4122(),
                'exception' => $e,
            ]);
        }
    }
}
