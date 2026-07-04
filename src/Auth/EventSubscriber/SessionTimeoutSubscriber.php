<?php

declare(strict_types=1);

namespace App\Auth\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces session timeouts for authenticated users:
 *  - idle: no request for IDLE_TTL seconds → logged out
 *  - absolute: session older than ABSOLUTE_TTL seconds → logged out, even if active
 *
 * Uses the session MetadataBag (created / last-used timestamps Symfony already
 * tracks), so no extra session writes are needed. On expiry the session is
 * invalidated and the user lands on /login with an explanatory flash.
 */
final class SessionTimeoutSubscriber implements EventSubscriberInterface
{
    private const IDLE_TTL = 1800;      // 30 min without a request
    private const ABSOLUTE_TTL = 7200;  // 2 h since login, regardless of activity

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8) so the token exists.
        return [KernelEvents::REQUEST => ['onKernelRequest', 6]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasPreviousSession() || $this->tokenStorage->getToken()?->getUser() === null) {
            return;
        }

        $metadata = $request->getSession()->getMetadataBag();
        $now = time();

        $idleExpired = ($now - $metadata->getLastUsed()) > self::IDLE_TTL;
        $absoluteExpired = ($now - $metadata->getCreated()) > self::ABSOLUTE_TTL;

        if (!$idleExpired && !$absoluteExpired) {
            return;
        }

        $session = $request->getSession();
        $session->invalidate();
        $this->tokenStorage->setToken(null);

        if ($session instanceof \Symfony\Component\HttpFoundation\Session\Session) {
            $session->getFlashBag()->add('info', $idleExpired
                ? 'You were signed out after 30 minutes of inactivity. Please sign in again.'
                : 'Your session reached its 2-hour limit. Please sign in again.');
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }
}
