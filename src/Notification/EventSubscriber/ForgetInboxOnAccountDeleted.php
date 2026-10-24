<?php

declare(strict_types=1);

namespace App\Notification\EventSubscriber;

use App\Account\Event\AccountDeleted;
use App\Notification\Repository\NotificationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** A deleted account's inbox has no retention basis (ADR-015 §1). Swallows failures. */
#[AsEventListener(event: AccountDeleted::class, priority: 0)]
final class ForgetInboxOnAccountDeleted
{
    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AccountDeleted $event): void
    {
        try {
            $this->notifications->deleteFor($event->user);
        } catch (\Throwable $e) {
            $this->logger->error('Could not delete a deleted account\'s notifications.', [
                'userId' => $event->summary->userId,
                'exception' => $e,
            ]);
        }
    }
}
