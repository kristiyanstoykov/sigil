<?php

declare(strict_types=1);

namespace App\Certificate\EventSubscriber;

use App\Certificate\Repository\CertificateRepository;
use App\Core\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The certificate gate: without a usable certificate a user can only reach
 * the dashboard (which shows deliveries addressed to them - receiving needs
 * no key), the document pages (uploading/storing/viewing needs no signing
 * key - only signing does), and the certificate pages. Everything else
 * redirects to the wizard. Mirrors TwoFactorEnrollmentSubscriber, which runs
 * first - this gate stays silent until 2FA enrollment is complete.
 */
final class CertificateEnrollmentSubscriber implements EventSubscriberInterface
{
    /** Routes (or route prefixes via app_certificate*) reachable without a certificate. */
    private const ALLOWED_ROUTES = [
        'app_dashboard',    // includes the inbox tab - deliveries can be received
        'app_logout',
        'app_2fa_setup',    // 2FA management stays reachable
        'app_2fa_disable',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CertificateRepository $certificates,
    ) {
    }

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
            return; // anonymous - firewall/login handles it
        }

        // still inside the 2FA enrollment or code-entry flow - let those gates win
        if (!$user->isGoogleAuthenticatorEnabled()
            || !$this->security->isGranted('IS_AUTHENTICATED_FULLY')) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');
        if (\in_array($route, self::ALLOWED_ROUTES, true)
            || str_starts_with($route, 'app_certificate')
            || str_starts_with($route, 'app_document')) {
            return;
        }

        if ($this->certificates->userHasUsableCertificate($user)) {
            return;
        }

        $event->setResponse(
            new RedirectResponse($this->urlGenerator->generate('app_certificate_new')),
        );
    }
}
