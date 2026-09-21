<?php

declare(strict_types=1);

namespace App\Core\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The response headers every page gets. The sign page is a PIN form - the one
 * place clickjacking pays - and app_document_view streams decrypted PDFs
 * inline, where content sniffing matters. The app iframes its own PDFs, so
 * framing is allowed from 'self' only, not forbidden outright.
 *
 * A full Content-Security-Policy is deliberately not here: Able Pro leans on
 * inline scripts and styles, so it needs nonces first. HSTS belongs on the
 * TLS-terminating proxy (compose.prod.yaml).
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        // PHP adds this at request start when expose_php is on; the ini turns
        // it off in the image, this covers any runtime that forgot.
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
