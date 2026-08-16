<?php

declare(strict_types=1);

namespace App\Delivery\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\DeliveryRecipient;
use App\Delivery\Event\DocumentDelivered;
use App\Document\Entity\Document;
use App\Document\Service\DocumentSharer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Serving a document: hand every recipient the key at once, record the moment,
 * and let Receipt seal the proof.
 *
 * There is no accept, no decline and no turn. A delivery cannot be refused -
 * that is what makes it a delivery (ADR-012) - so this class has exactly one
 * operation and no lifecycle.
 */
final class DeliveryService
{
    public function __construct(
        private readonly RecipientEligibility $eligibility,
        private readonly DocumentSharer $sharer,
        private readonly DeliveryNotifier $notifier,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $events,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Serve $document on $recipients. All or nothing: if one address cannot be
     * served, nobody is - the same rule the signer list follows, and for the same
     * reason. A half-made delivery would need a receipt attesting half of it.
     *
     * @param list<User> $recipients
     *
     * @throws DomainException on a bad list or an ineligible recipient
     */
    public function deliver(Document $document, User $sender, array $recipients, ?string $note = null): Delivery
    {
        if ($document->getOwner()->getId()->toRfc4122() !== $sender->getId()->toRfc4122()) {
            throw new DomainException('Only the owner can deliver this document.');
        }

        $version = $document->getLatestVersion()
            ?? throw new DomainException('This document has no content to deliver.');

        if ([] === $recipients) {
            throw new DomainException('Add at least one recipient.');
        }

        $unique = [];
        foreach ($recipients as $recipient) {
            $id = $recipient->getId()->toRfc4122();
            if (isset($unique[$id])) {
                throw new DomainException(sprintf('%s is on the list twice.', $recipient->getEmail()));
            }
            if ($id === $sender->getId()->toRfc4122()) {
                throw new DomainException('You already have this document.');
            }
            $unique[$id] = $recipient;

            if (null !== $reason = $this->eligibility->reasonWhyNot($recipient)) {
                throw new DomainException($reason);
            }
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $delivery = new Delivery($document, $sender, $note);
        $this->em->persist($delivery);

        foreach ($recipients as $recipient) {
            $this->em->persist(new DeliveryRecipient($delivery, $recipient, $version, $now));
        }

        $this->em->flush();

        // The grant IS the consignment: re-wrap this version's DEK for each
        // recipient, exactly as a signing turn does (ADR-004, re-wrap never
        // re-encrypt). Only this version - a later one is not served retroactively.
        foreach ($recipients as $recipient) {
            $this->sharer->grantVersion($version, $sender, $recipient);

            $this->auditLogger->log(
                action: 'delivery.served',
                actor: $sender,
                payload: [
                    'deliveryId' => $delivery->getId()->toRfc4122(),
                    'recipient' => $recipient->getEmail(),
                    'versionNumber' => $version->getVersionNumber(),
                    'deliveredAt' => $now->format(\DATE_ATOM),
                ],
                subjectType: 'Document',
                subjectId: $document->getId()->toRfc4122(),
            );

            $this->notifier->notifyServed($delivery, $recipient);
        }

        $this->events->dispatch(new DocumentDelivered($delivery));

        return $delivery;
    }
}
