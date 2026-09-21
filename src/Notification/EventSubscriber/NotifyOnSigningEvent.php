<?php

declare(strict_types=1);

namespace App\Notification\EventSubscriber;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Notification\Enum\NotificationType;
use App\Notification\Service\Notifier;
use App\Signing\Enum\SigningRequestStatus;
use App\Signing\Event\DocumentSigned;
use App\Signing\Event\SigningRequestClosed;
use App\Signing\Event\SigningTurnReached;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * In-app notifications for the signing queue.
 *
 * Every handler swallows its own failures. This is the opposite policy from the
 * audit log, which must never swallow: an audit entry is part of what happened,
 * a notification is only how someone hears about it, and a signature that has
 * already been made cannot be undone because an inbox row would not write.
 */
final class NotifyOnSigningEvent
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: SigningTurnReached::class, priority: 0)]
    public function onTurnReached(SigningTurnReached $event): void
    {
        $document = $event->request->getDocument();

        $this->guard(function () use ($event, $document): void {
            $this->notifier->notify(
                recipient: $event->signer->getUser(),
                type: NotificationType::SignatureRequested,
                title: sprintf('Your signature is requested: %s', $document->getTitle()),
                url: $this->url('app_document_sign', $document),
                body: sprintf(
                    '%s asked you to sign this document. You are signer %d of %d.',
                    $event->request->getRequester()->getFullName(),
                    $event->signer->getPosition(),
                    \count($event->request->getSigners()),
                ),
                documentId: $document->getId(),
            );
        });
    }

    #[AsEventListener(event: DocumentSigned::class, priority: 0)]
    public function onDocumentSigned(DocumentSigned $event): void
    {
        $owner = $event->document->getOwner();

        // Nobody needs telling they did the thing they just did.
        if ($owner->is($event->signer)) {
            return;
        }

        $this->guard(function () use ($event, $owner): void {
            $this->notifier->notify(
                recipient: $owner,
                type: NotificationType::DocumentSigned,
                title: sprintf('%s signed %s', $event->signer->getFullName(), $event->document->getTitle()),
                url: $this->url('app_document_show', $event->document),
                body: $event->remaining > 0
                    ? sprintf('%d signer(s) still to go.', $event->remaining)
                    : null,
                documentId: $event->document->getId(),
            );
        });
    }

    /**
     * Completed goes to the requester; any other ending also goes to whoever was
     * holding the turn when it closed, which is the same audience the mail has.
     */
    #[AsEventListener(event: SigningRequestClosed::class, priority: 0)]
    public function onRequestClosed(SigningRequestClosed $event): void
    {
        $request = $event->request;
        $document = $request->getDocument();
        $status = $request->getStatus();

        if (SigningRequestStatus::Completed === $status) {
            $this->guard(function () use ($request, $document): void {
                $this->notifier->notify(
                    recipient: $request->getRequester(),
                    type: NotificationType::SigningCompleted,
                    title: sprintf('Everyone signed: %s', $document->getTitle()),
                    url: $this->url('app_document_show', $document),
                    body: sprintf('All %d signers have signed.', \count($request->getSigners())),
                    documentId: $document->getId(),
                );
            });

            return;
        }

        $declined = $request->declinedBy();
        $body = match ($status) {
            SigningRequestStatus::Expired => 'The deadline passed before everyone signed.',
            SigningRequestStatus::Declined => sprintf(
                '%s declined.%s',
                $declined?->getUser()->getFullName() ?? 'A signer',
                null !== $declined?->getDeclineReason() ? ' Reason: '.$declined->getDeclineReason() : '',
            ),
            default => 'The requester withdrew it.',
        };

        $audience = [$request->getRequester()];
        $pending = $request->currentSigner();
        if (null !== $pending) {
            $audience[] = $pending->getUser();
        }

        /** @var list<User> $audience */
        $seen = [];
        foreach ($audience as $user) {
            $id = $user->getId()->toRfc4122();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $this->guard(function () use ($user, $document, $status, $body): void {
                $this->notifier->notify(
                    recipient: $user,
                    type: NotificationType::SigningClosed,
                    title: sprintf('Signature request %s: %s', $status->value, $document->getTitle()),
                    // The document may be unreadable to a decliner once the grant
                    // is revoked, so point at the request's own history instead.
                    url: $this->urls->generate('app_signing_requests', ['tab' => 'history'], UrlGeneratorInterface::ABSOLUTE_URL),
                    body: $body,
                    documentId: $document->getId(),
                );
            });
        }
    }

    private function url(string $route, Document $document): string
    {
        return $this->urls->generate(
            $route,
            ['id' => $document->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    private function guard(callable $write): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $this->logger->error('Could not store a notification.', ['exception' => $e]);
        }
    }
}
