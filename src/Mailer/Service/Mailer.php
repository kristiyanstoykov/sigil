<?php

declare(strict_types=1);

namespace App\Mailer\Service;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Central outbound-mail entry point. Owns the sender identity so no controller or
 * service has to know the "From" address - they build a TemplatedEmail (to /
 * subject / template / context) and hand it here. The sender is configured once
 * via MAILER_FROM / MAILER_FROM_NAME (see .env) and must be a Brevo-verified
 * sender, otherwise delivery is rejected.
 */
final class Mailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $fromName,
    ) {}

    /**
     * Send an email, stamping the configured sender unless one was set explicitly.
     *
     * @throws TransportExceptionInterface when the mail provider rejects the send
     */
    public function send(TemplatedEmail $email): void
    {
        if ($email->getFrom() === []) {
            $email->from($this->from());
        }

        $this->mailer->send($email);
    }

    /**
     * Send an email, swallowing provider/transport failures (misconfigured DSN,
     * rejected sender IP, provider outage, …). Failures are logged and reported
     * via the return value so the caller decides what the user should see -
     * a provider hiccup must never 500 a user-facing flow like registration.
     */
    public function trySend(TemplatedEmail $email): bool
    {
        try {
            $this->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Outbound email failed: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
                'subject' => $email->getSubject(),
            ]);

            return false;
        }
    }

    public function from(): Address
    {
        return new Address($this->fromEmail, $this->fromName);
    }
}
