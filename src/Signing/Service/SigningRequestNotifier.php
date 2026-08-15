<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Mailer\Service\Mailer;
use App\Signing\Entity\SigningRequest;
use App\Signing\Entity\SigningRequestSigner;
use App\Signing\Enum\SigningRequestStatus;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Outbound mail for the signing queue. Every send goes through Mailer::trySend,
 * so a provider outage can never break a signature that already happened.
 */
final class SigningRequestNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /** Tell a signer the queue has reached them. */
    public function notifyTurn(SigningRequest $request, SigningRequestSigner $signer): void
    {
        $document = $request->getDocument();

        $this->mailer->trySend(
            (new TemplatedEmail())
                ->to($signer->getUser()->getEmail())
                ->subject(sprintf('Your signature is requested: %s', $document->getTitle()))
                ->htmlTemplate('emails/signing_turn.html.twig')
                ->context([
                    'title' => $document->getTitle(),
                    'requester' => $request->getRequester()->getFullName(),
                    'position' => $signer->getPosition(),
                    'total' => \count($request->getSigners()),
                    'deadline' => $request->getDeadline(),
                    'url' => $this->signUrl($document),
                ]),
        );
    }

    /** Tell the owner that someone else signed their document. */
    public function notifySigned(Document $document, User $signer, int $remaining = 0): void
    {
        $owner = $document->getOwner();
        if ($owner->getId()->toRfc4122() === $signer->getId()->toRfc4122()) {
            return;
        }

        $this->mailer->trySend(
            (new TemplatedEmail())
                ->to($owner->getEmail())
                ->subject(sprintf('%s signed %s', $signer->getFullName(), $document->getTitle()))
                ->htmlTemplate('emails/document_signed.html.twig')
                ->context([
                    'title' => $document->getTitle(),
                    'signedBy' => $signer->getFullName(),
                    'remaining' => $remaining,
                    'url' => $this->showUrl($document),
                ]),
        );
    }

    public function notifyCompleted(SigningRequest $request): void
    {
        $document = $request->getDocument();

        $this->mailer->trySend(
            (new TemplatedEmail())
                ->to($request->getRequester()->getEmail())
                ->subject(sprintf('Everyone signed: %s', $document->getTitle()))
                ->htmlTemplate('emails/signing_completed.html.twig')
                ->context([
                    'title' => $document->getTitle(),
                    'total' => \count($request->getSigners()),
                    'url' => $this->showUrl($document),
                ]),
        );
    }

    /**
     * Expired, cancelled or declined: the requester always hears about it, and so
     * does the signer who was holding the turn when it closed.
     */
    public function notifyClosed(SigningRequest $request, SigningRequestStatus $status): void
    {
        if (SigningRequestStatus::Completed === $status) {
            return;
        }

        $document = $request->getDocument();
        $declined = $request->declinedBy();

        $subject = match ($status) {
            SigningRequestStatus::Expired => 'Signature request expired',
            SigningRequestStatus::Declined => 'Signature request declined',
            default => 'Signature request cancelled',
        };

        $recipients = [$request->getRequester()->getEmail()];
        $pending = $request->currentSigner();
        if (null !== $pending) {
            $recipients[] = $pending->getUser()->getEmail();
        }

        foreach (array_unique($recipients) as $recipient) {
            $this->mailer->trySend(
                (new TemplatedEmail())
                    ->to($recipient)
                    ->subject(sprintf('%s: %s', $subject, $document->getTitle()))
                    ->htmlTemplate('emails/signing_closed.html.twig')
                    ->context([
                        'title' => $document->getTitle(),
                        'status' => $status->value,
                        'expired' => SigningRequestStatus::Expired === $status,
                        'requester' => $request->getRequester()->getFullName(),
                        'deadline' => $request->getDeadline(),
                        'signed' => $request->signedCount(),
                        'declinedBy' => $declined?->getUser()->getFullName(),
                        'declineReason' => $declined?->getDeclineReason(),
                    ]),
            );
        }
    }

    private function showUrl(Document $document): string
    {
        return $this->urls->generate(
            'app_document_show',
            ['id' => $document->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function signUrl(Document $document): string
    {
        return $this->urls->generate(
            'app_document_sign',
            ['id' => $document->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
