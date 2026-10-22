<?php

declare(strict_types=1);

namespace App\Account\EventSubscriber;

use App\Account\Event\AccountDeleted;
use App\Mailer\Service\Mailer;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/** Mail listeners swallow and log (ADR-013): the account is deleted either way. */
#[AsEventListener(event: AccountDeleted::class, priority: 0)]
final class SendAccountDeletedMail
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AccountDeleted $event): void
    {
        $summary = $event->summary;

        try {
            $this->mailer->trySend(
                (new TemplatedEmail())
                    ->to($event->formerEmail)
                    ->subject('Your Sigil account has been deleted')
                    ->htmlTemplate('emails/account_deleted.html.twig')
                    ->context([
                        'name' => $event->formerName,
                        'deletedAt' => $summary->deletedAt,
                        'retainUntil' => $summary->retainUntil,
                        'recoveryCode' => $summary->recoveryCode,
                        'requestsClosed' => \count($summary->closedRequests),
                        'certificatesRevoked' => \count($summary->revokedSerials),
                        'draftsErased' => $summary->draftsErased,
                        'documentsRetained' => $summary->documentsRetained,
                    ]),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Could not send the account deletion mail.', [
                'userId' => $summary->userId,
                'exception' => $e,
            ]);
        }
    }
}
