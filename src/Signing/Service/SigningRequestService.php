<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentVersion;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Service\DocumentSharer;
use App\Signing\Entity\SigningRequest;
use App\Signing\Entity\SigningRequestSigner;
use App\Signing\Enum\SigningRequestStatus;
use App\Signing\Repository\SigningRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * The signing queue: create a request over an ordered signer list, hand the turn
 * to one signer at a time, and close the request when it completes, expires or
 * is cancelled (ADR-007).
 *
 * Read access is granted per turn, never up front - a signer can decrypt the
 * document only once it is actually their turn, and the grants stay the access
 * list (ADR-004).
 */
final class SigningRequestService
{
    public function __construct(
        private readonly SigningRequestRepository $requests,
        private readonly SignerEligibility $eligibility,
        private readonly DocumentSharer $sharer,
        private readonly DocumentKeyGrantRepository $grants,
        private readonly SigningRequestNotifier $notifier,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Send a request. Every signer is checked here and only here; the list goes
     * out whole or not at all.
     *
     * @param list<User> $signers in signing order, first signs first
     *
     * @throws DomainException on a bad list, a bad deadline, or an ineligible signer
     */
    public function create(Document $document, User $requester, array $signers, \DateTimeImmutable $deadline): SigningRequest
    {
        if ($document->getOwner()->getId()->toRfc4122() !== $requester->getId()->toRfc4122()) {
            throw new DomainException('Only the owner can request signatures for this document.');
        }

        $latest = $document->getLatestVersion()
            ?? throw new DomainException('This document has no content to sign.');

        if (null !== $this->requests->findPendingForDocument($document)) {
            throw new DomainException('This document already has a signature request out.');
        }

        if ([] === $signers) {
            throw new DomainException('Add at least one signer.');
        }

        $unique = [];
        foreach ($signers as $signer) {
            $id = $signer->getId()->toRfc4122();
            if (isset($unique[$id])) {
                throw new DomainException(sprintf('%s is on the list twice.', $signer->getEmail()));
            }
            $unique[$id] = $signer;

            if (null !== $reason = $this->eligibility->reasonWhyNot($signer)) {
                throw new DomainException($reason);
            }
        }

        $this->assertDeadline($deadline);

        $request = new SigningRequest($document, $requester, $deadline);
        $this->em->persist($request);

        $position = 1;
        foreach ($signers as $signer) {
            $this->em->persist(new SigningRequestSigner($request, $signer, $position));
            ++$position;
        }

        $this->em->flush();

        // The turn is the access: only the first signer can read the document now.
        $first = $request->orderedSigners()[0];
        $this->sharer->grantVersion($latest, $requester, $first->getUser());

        $this->auditLogger->log(
            action: 'signing_request.created',
            actor: $requester,
            payload: [
                'requestId' => $request->getId()->toRfc4122(),
                'signers' => implode(', ', array_map(static fn (User $u): string => $u->getEmail(), $signers)),
                'deadline' => $deadline->format(\DATE_ATOM),
            ],
            subjectType: 'Document',
            subjectId: $document->getId()->toRfc4122(),
        );

        $this->notifier->notifyTurn($request, $first);

        return $request;
    }

    /**
     * Record that the current signer signed, then hand the turn on: the next
     * signer is granted the version that was just produced, or the request closes
     * as completed.
     */
    public function recordSignature(SigningRequest $request, User $signer, DocumentVersion $version): void
    {
        $entry = $request->signerFor($signer);
        if (!$request->isTurnOf($signer) || null === $entry) {
            throw new DomainException('It is not your turn to sign this document.');
        }

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $entry->markSigned($version, $now);
        $this->em->flush();

        $next = $request->currentSigner();
        if (null === $next) {
            $request->close(SigningRequestStatus::Completed, $now);
            $this->em->flush();

            $this->audit($request, 'signing_request.completed', $signer, []);
            $this->notifier->notifyCompleted($request);

            return;
        }

        $this->sharer->grantVersion($version, $signer, $next->getUser());
        $this->audit($request, 'signing_request.turn_advanced', $signer, [
            'nextSigner' => $next->getUser()->getEmail(),
            'position' => $next->getPosition(),
        ]);
        $this->notifier->notifyTurn($request, $next);
    }

    /**
     * Withdraw a request the requester no longer wants. Signatures already
     * collected stay - they are versions of the document, not part of this row.
     */
    public function cancel(SigningRequest $request, User $actor): void
    {
        if ($request->getRequester()->getId()->toRfc4122() !== $actor->getId()->toRfc4122()) {
            throw new DomainException('Only the requester can cancel this request.');
        }

        if (!$request->isPending()) {
            throw new DomainException('This request is already closed.');
        }

        $this->close($request, SigningRequestStatus::Cancelled, $actor);
    }

    /** Close an overdue request. Called by the sweep, so the actor is the requester. */
    public function expire(SigningRequest $request): void
    {
        $this->close($request, SigningRequestStatus::Expired, $request->getRequester());
    }

    private function close(SigningRequest $request, SigningRequestStatus $status, User $actor): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $pending = $request->currentSigner();

        $request->close($status, $now);
        $this->em->flush();

        // Whoever held the turn loses the access that came with it. Signers who
        // already signed keep theirs: they are on the record as having signed.
        if (null !== $pending) {
            $deleted = $this->grants->deleteForDocumentAndUser($request->getDocument(), $pending->getUser());
            if ($deleted > 0) {
                $this->audit($request, 'signing_request.access_revoked', $actor, [
                    'signer' => $pending->getUser()->getEmail(),
                    'grantsDeleted' => $deleted,
                ]);
            }
        }

        $this->audit($request, 'signing_request.'.$status->value, $actor, []);
        $this->notifier->notifyClosed($request, $status);
    }

    /** @throws DomainException if the deadline is in the past or beyond the 30-day ceiling */
    private function assertDeadline(\DateTimeImmutable $deadline): void
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        if ($deadline <= $now) {
            throw new DomainException('The signing deadline must be in the future.');
        }

        if ($deadline > $now->modify(sprintf('+%d days', SigningRequest::MAX_DEADLINE_DAYS))) {
            throw new DomainException(sprintf('The signing deadline can be at most %d days away.', SigningRequest::MAX_DEADLINE_DAYS));
        }
    }

    /**
     * @param array<string, scalar> $payload
     */
    private function audit(SigningRequest $request, string $action, User $actor, array $payload): void
    {
        $this->auditLogger->log(
            action: $action,
            actor: $actor,
            payload: array_merge(['requestId' => $request->getId()->toRfc4122()], $payload),
            subjectType: 'Document',
            subjectId: $request->getDocument()->getId()->toRfc4122(),
        );
    }
}
