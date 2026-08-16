<?php

declare(strict_types=1);

namespace App\Delivery\Service;

use App\Core\Entity\User;
use App\Delivery\Entity\Delivery;
use App\Mailer\Service\Mailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Outbound mail for deliveries. Through Mailer::trySend like everything else: a
 * provider outage must not undo a delivery that has already been made and
 * attested.
 */
final class DeliveryNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Tell a recipient a document has been served on them. Notice, not request -
     * there is no action to take and nothing to accept.
     */
    public function notifyServed(Delivery $delivery, User $recipient): void
    {
        $document = $delivery->getDocument();

        $this->mailer->trySend(
            (new TemplatedEmail())
                ->to($recipient->getEmail())
                ->subject(sprintf('Document delivered to you: %s', $document->getTitle()))
                ->htmlTemplate('emails/document_delivered.html.twig')
                ->context([
                    'title' => $document->getTitle(),
                    'sender' => $delivery->getSender()->getFullName(),
                    'note' => $delivery->getNote(),
                    'url' => $this->urls->generate(
                        'app_document_show',
                        ['id' => $document->getId()->toRfc4122()],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                ]),
        );
    }
}
