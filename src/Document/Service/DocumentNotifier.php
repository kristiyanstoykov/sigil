<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Mailer\Service\Mailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Outbound mail for document events. Sends are best-effort: an upload or a share
 * has already happened by the time we get here, so a mail failure must not undo it.
 */
final class DocumentNotifier
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function notifyUploaded(Document $document): void
    {
        $this->mailer->trySend(
            (new TemplatedEmail())
                ->to($document->getOwner()->getEmail())
                ->subject(sprintf('Uploaded to Sigil: %s', $document->getTitle()))
                ->htmlTemplate('emails/document_uploaded.html.twig')
                ->context([
                    'title' => $document->getTitle(),
                    'url' => $this->showUrl($document),
                ]),
        );
    }

    public function notifyShared(Document $document, User $recipient, User $sharedBy): void
    {
        $this->mailer->trySend(
            (new TemplatedEmail())
                ->to($recipient->getEmail())
                ->subject(sprintf('%s shared a document with you', $sharedBy->getFullName()))
                ->htmlTemplate('emails/document_shared.html.twig')
                ->context([
                    'title' => $document->getTitle(),
                    'sharedBy' => $sharedBy->getFullName(),
                    'url' => $this->showUrl($document),
                ]),
        );
    }

    private function showUrl(Document $document): string
    {
        return $this->urls->generate(
            'app_document_show',
            ['id' => $document->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
