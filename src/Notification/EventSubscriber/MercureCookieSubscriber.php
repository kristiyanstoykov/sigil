<?php

declare(strict_types=1);

namespace App\Notification\EventSubscriber;

use App\Core\Entity\User;
use App\Notification\Service\InboxTopic;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mercure\Authorization;

/**
 * Hands the browser its permission to listen to its own inbox.
 *
 * The hub is a separate process: it cannot read the session, the database or a
 * User, so "may this browser hear this topic?" has to be answerable from the
 * request alone. The answer is a JWT the app signs with the key the hub also
 * holds, carried in an HttpOnly cookie the browser then sends with its
 * EventSource request.
 *
 * The grant is exactly one topic - the holder's own inbox. There is no wildcard
 * and no second topic, so a leaked cookie buys its holder nothing but their own
 * notifications, and one user can never subscribe to another's.
 *
 * Re-signed on every HTML response rather than only when the cookie is absent:
 * the JWT expires an hour after it is minted, and the browser goes on sending
 * the stale one until it is replaced.
 *
 * Priority 10 puts this ahead of the component's own SetCookieSubscriber (0),
 * which is what actually copies the cookie onto the response. Behind it, the
 * cookie would be minted after the only listener that could attach it.
 */
final class MercureCookieSubscriber
{
    public function __construct(
        private readonly Authorization $authorization,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: 10)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $response = $event->getResponse();
        if (!str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')) {
            return;
        }

        try {
            $this->authorization->setCookie($event->getRequest(), [InboxTopic::for($user)]);
        } catch (\Throwable $e) {
            // A misconfigured hub must not take the page down with it; the bell
            // still renders from the database, just without the live push.
            $this->logger->warning('Could not mint the Mercure subscriber cookie.', ['exception' => $e]);
        }
    }
}
