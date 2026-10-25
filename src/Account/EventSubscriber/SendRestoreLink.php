<?php

declare(strict_types=1);

namespace App\Account\EventSubscriber;

use App\Auth\Event\DeletedAccountRegistration;
use App\Mailer\Service\Mailer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Mails the address a signed, one-hour link to the restore page (ADR-015 §4).
 * Reaching the inbox proves the mailbox; the page then asks for the recovery code.
 */
#[AsEventListener(event: DeletedAccountRegistration::class)]
final class SendRestoreLink
{
    public const string TTL = '+1 hour';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly UriSigner $signer,
        private readonly Mailer $mailer,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.account_restore_link')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    public function __invoke(DeletedAccountRegistration $event): void
    {
        $user = $event->deletedUser;
        if (!$this->limiter->create((string) $user->getEmailFingerprint())->consume()->isAccepted()) {
            $this->logger->notice('Restore link not sent: too many requests for this address.', ['userId' => $user->getId()->toRfc4122()]);

            return;
        }

        $expiresAt = $this->clock->now()->modify(self::TTL);
        $url = $this->signer->sign($this->urls->generate('app_account_restore', [
            'id' => $user->getId()->toRfc4122(),
            'email' => $event->email,
        ], UrlGeneratorInterface::ABSOLUTE_URL), $expiresAt);

        $this->mailer->trySend(
            (new TemplatedEmail())
                ->to($event->email)
                ->subject('Restore your Sigil account')
                ->htmlTemplate('emails/account_restore_link.html.twig')
                ->context(['url' => $url, 'expiresAt' => $expiresAt, 'deletedAt' => $user->getDeletedAt()]),
        );
    }
}
