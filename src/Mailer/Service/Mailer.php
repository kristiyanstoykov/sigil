<?php

declare(strict_types=1);

namespace App\Mailer\Service;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Central outbound-mail entry point. Owns the sender identity so no controller or
 * service has to know the "From" address — they build a TemplatedEmail (to /
 * subject / template / context) and hand it here. The sender is configured once
 * via MAILER_FROM / MAILER_FROM_NAME (see .env) and must be a Brevo-verified
 * sender, otherwise delivery is rejected.
 */
final class Mailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $fromEmail,
        #[Autowire('%env(MAILER_FROM_NAME)%')] private readonly string $fromName,
    ) {}

    /**
     * Send an email, stamping the configured sender unless one was set explicitly.
     */
    public function send(TemplatedEmail $email): void
    {
        if ($email->getFrom() === []) {
            $email->from($this->from());
        }

        $this->mailer->send($email);
    }

    public function from(): Address
    {
        return new Address($this->fromEmail, $this->fromName);
    }
}
