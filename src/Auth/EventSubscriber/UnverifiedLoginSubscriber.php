<?php

declare(strict_types=1);

namespace App\Auth\EventSubscriber;

use App\Auth\Security\UnverifiedEmailException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * When a login fails because the account's email is not verified, send the user
 * to the resend-verification page instead of showing the generic login error.
 * The attempted email is stashed in the session so the resend form is prefilled
 * - it is never put in the URL.
 */
final class UnverifiedLoginSubscriber implements EventSubscriberInterface
{
    public const SESSION_EMAIL_KEY = 'resend_verification_email';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [LoginFailureEvent::class => 'onLoginFailure'];
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (!$event->getException() instanceof UnverifiedEmailException) {
            return;
        }

        $request = $event->getRequest();
        $email = $request->request->getString('email');

        if ($email !== '' && $request->hasSession()) {
            $request->getSession()->set(self::SESSION_EMAIL_KEY, $email);
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('app_verify_resend'),
        ));
    }
}
