<?php

declare(strict_types=1);

namespace App\Auth\EventSubscriber;

use App\Core\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Enforces mandatory 2FA enrollment.
 *
 * 2FA is required for every account (ADR / MVP policy). A user who has
 * authenticated with their password but has not yet enabled TOTP is fully
 * authenticated in Symfony's eyes (scheb only intercepts accounts that
 * already have a secret), so nothing else stops them reaching the app. This
 * gate redirects such users to the setup page and blocks every other route
 * until enrollment completes.
 *
 * Runs after the firewall (priority 0 < firewall's 8), so the security token
 * is populated by the time it executes.
 */
final class TwoFactorEnrollmentSubscriber implements EventSubscriberInterface
{
    /** Routes a not-yet-enrolled user is still allowed to reach. */
    private const ALLOWED_ROUTES = [
        'app_2fa_setup',   // the enrollment page itself (GET form + POST confirm)
        'app_logout',      // must always be able to leave
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'onKernelRequest'];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return; // anonymous (login, register, assets) - nothing to enforce
        }

        if ($user->isGoogleAuthenticatorEnabled()) {
            return; // already enrolled
        }

        // Password accepted but 2FA code not yet entered: let scheb finish its
        // own flow rather than fighting it. (Only reachable once a secret exists.)
        if (!$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (\in_array($route, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate('app_2fa_setup')),
        );
    }
}
