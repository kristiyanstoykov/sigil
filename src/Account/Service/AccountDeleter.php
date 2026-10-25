<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\AccountClosureRecord;
use App\Account\Entity\ArchivedUserKey;
use App\Account\Event\AccountDeleted;
use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Auth\Security\EmailFingerprinter;
use App\Certificate\Entity\Certificate;
use App\Certificate\Repository\CertificateRepository;
use App\Certificate\Service\CertificateIssuer;
use App\Certificate\Service\PinHasher;
use App\Core\Doctrine\RowLock;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Repository\UserEncryptionKeyRepository;
use App\Document\Service\DocumentEraser;
use App\Document\Service\KeyManagementService;
use App\Signing\Entity\SigningRequest;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\SigningRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Deletes an account in the order ADR-015 §3 fixes: close its requests, revoke
 * its certificates, erase the drafts nobody else holds, then archive the KEK
 * and tombstone the row in one locked transaction, with the sealed closure
 * record. Each step commits on its
 * own, so a run that stops part-way (a request closed under it) is simply
 * started again and carries on from where it got to.
 *
 * Proving who is asking (password, TOTP, typed email) is the caller's job.
 */
final class AccountDeleter
{
    public const string RETRY_MESSAGE = 'Something changed while your account was being deleted. Please try again.';

    public function __construct(
        private readonly SigningRequestRepository $requests,
        private readonly SigningRequestService $signing,
        private readonly CertificateRepository $certificates,
        private readonly CertificateIssuer $issuer,
        private readonly DocumentRepository $documents,
        private readonly DocumentKeyGrantRepository $grants,
        private readonly DocumentEraser $eraser,
        private readonly UserEncryptionKeyRepository $keys,
        private readonly KeyManagementService $keyManagement,
        private readonly ArchiveKeyWrapper $archive,
        private readonly EmailFingerprinter $fingerprinter,
        private readonly PinHasher $hasher,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly EventDispatcherInterface $events,
        private readonly ClosureRecordSealer $closureRecords,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws DomainException when the account is already deleted, or something changed under the run */
    public function delete(User $user): AccountDeletionSummary
    {
        if ($user->isDeleted()) {
            throw new DomainException('This account has already been deleted.');
        }

        $closed = $this->closeRequests($user);
        $revoked = $this->revokeCertificates($user);
        [$erased, $retained] = $this->eraseUnsharedDrafts($user);

        $formerEmail = $user->getEmail();
        $formerName = $user->getFullName();
        $code = RecoveryCode::generate();
        $codeHash = $this->hasher->hash(RecoveryCode::normalize($code));
        $fingerprint = $this->fingerprinter->fingerprint($formerEmail);
        $now = $this->clock->now();

        $summary = new AccountDeletionSummary(
            userId: $user->getId()->toRfc4122(),
            deletedAt: $now,
            retainUntil: $now->modify('+'.ArchivedUserKey::RETENTION_YEARS.' years'),
            closedRequests: $closed,
            revokedSerials: $revoked,
            draftsErased: $erased,
            documentsRetained: $retained,
            recoveryCode: $code,
        );

        // The token steps run before the transaction, as the signing step does.
        // The closure record is wrapped under the KEK while it is still live
        // (minting one if need be), so it is archived with everything else.
        [$record, $recordPdf] = $this->sealClosureRecord($user, $formerName, $formerEmail, $summary);
        $hadKey = null !== $this->keys->findForUser($user);
        $archived = $hadKey ? $this->archivedKek($user) : null;

        try {
            $this->em->wrapInTransaction(function () use ($user, $hadKey, $archived, $record, $fingerprint, $codeHash, $now, $closed, $revoked, $erased, $retained): void {
                RowLock::acquire($this->em, $user);
                if ($user->isDeleted()) {
                    throw new DomainException('This account has already been deleted.');
                }
                // A request sent to them, or a key minted for them, since the steps above.
                $keyRow = $this->keys->findForUser($user);
                if ($hadKey !== (null !== $keyRow) || [] !== $this->openRequests($user)) {
                    throw new DomainException(self::RETRY_MESSAGE);
                }

                if (null !== $keyRow && null !== $archived) {
                    $this->em->persist(new ArchivedUserKey($user, $archived, $now));
                    $this->em->remove($keyRow);
                }
                if (null !== $record) {
                    $this->em->persist($record);
                }
                $user->tombstone($now, $fingerprint, $codeHash);
                $this->em->flush();

                $this->auditLogger->log(
                    action: 'account.deleted',
                    actor: $user,
                    payload: [
                        'requestsClosed' => \count($closed),
                        'certificatesRevoked' => \count($revoked),
                        'draftsErased' => $erased,
                        'documentsRetained' => $retained,
                        'keyArchived' => null !== $keyRow,
                        'closureRecordId' => $record?->getId()->toRfc4122(),
                    ],
                    subjectType: 'User',
                    subjectId: $user->getId()->toRfc4122(),
                    severity: AuditSeverity::Warning,
                );
            });
        } catch (\Throwable $e) {
            if (null !== $record) {
                $this->closureRecords->discard($record);
            }

            throw $e;
        }

        $this->events->dispatch(new AccountDeleted($user, $formerEmail, $formerName, $summary, $recordPdf));

        return $summary;
    }

    /**
     * What delete() would do right now, for the consequences page.
     *
     * @return array{requestsStarted: int, requestsWaiting: int, certificates: int, draftsErased: int, documentsRetained: int}
     */
    public function preview(User $user): array
    {
        $open = $this->openRequests($user);
        $started = \count(array_filter($open, static fn (SigningRequest $r): bool => $r->getRequester()->is($user)));
        $documents = $this->documents->findByOwner($user);
        $drafts = \count(array_filter($documents, $this->isUnsharedDraft(...)));

        return [
            'requestsStarted' => $started,
            'requestsWaiting' => \count($open) - $started,
            'certificates' => \count(array_filter($this->certificates->findByUser($user), static fn (Certificate $c): bool => null === $c->getRevokedAt())),
            'draftsErased' => $drafts,
            'documentsRetained' => \count($documents) - $drafts,
        ];
    }

    /** @return array<string, string> request id => how it closed */
    private function closeRequests(User $user): array
    {
        $closed = [];
        foreach ($this->openRequests($user) as $request) {
            $status = $this->signing->closeForDeletedAccount($request, $user);
            if (null !== $status) {
                $closed[$request->getId()->toRfc4122()] = $status->value;
            }
        }

        return $closed;
    }

    /**
     * Pending requests deletion has to close: every one they started, and every
     * one still waiting on their signature. One they already signed carries on.
     *
     * @return list<SigningRequest>
     */
    private function openRequests(User $user): array
    {
        $open = [];
        foreach ([...$this->requests->findPendingByRequester($user), ...$this->requests->findPendingForSigner($user)] as $request) {
            $mine = $request->getRequester()->is($user);
            if (!$mine && true === $request->signerFor($user)?->hasSigned()) {
                continue;
            }
            $open[$request->getId()->toRfc4122()] = $request;
        }

        return array_values($open);
    }

    /** @return list<string> serial numbers revoked */
    private function revokeCertificates(User $user): array
    {
        $revoked = [];
        foreach ($this->certificates->findByUser($user) as $certificate) {
            if (null === $certificate->getRevokedAt()) {
                $this->issuer->revoke($certificate, $user, Certificate::REVOCATION_CESSATION_OF_OPERATION);
                $revoked[] = $certificate->getSerialNumber();
            }
        }

        return $revoked;
    }

    /** @return array{int, int} drafts erased, documents retained */
    private function eraseUnsharedDrafts(User $user): array
    {
        $erased = 0;
        $retained = 0;
        foreach ($this->documents->findByOwner($user) as $document) {
            if ($this->isUnsharedDraft($document)) {
                // Its closed requests go through the ORM first, as in the sweep:
                // the database cascade would leave them managed over deleted rows.
                foreach ($this->requests->findAllForDocument($document) as $request) {
                    $this->em->remove($request);
                }
                $this->em->flush();
                $this->eraser->erase($document, $user, 'account_deleted');
                ++$erased;
            } else {
                ++$retained;
            }
        }

        return [$erased, $retained];
    }

    /** No signature, never delivered, and nobody but the owner holds a key to it (ADR-015 §3). */
    private function isUnsharedDraft(Document $document): bool
    {
        if ($document->isSigned() || $document->isDelivered() || $document->isAwaitingSignatures()) {
            return false;
        }

        foreach ($this->grants->findForDocument($document) as $grant) {
            if (!$grant->getUser()->is($document->getOwner())) {
                return false;
            }
        }

        return true;
    }

    /**
     * A sealing failure is audited and never stops the deletion (ADR-015 §3).
     *
     * @return array{?AccountClosureRecord, ?string}
     */
    private function sealClosureRecord(User $user, string $formerName, string $formerEmail, AccountDeletionSummary $summary): array
    {
        try {
            $sealed = $this->closureRecords->seal($user, $formerName, $formerEmail, $summary);

            return [$sealed['record'], $sealed['pdf']];
        } catch (\Throwable $e) {
            $this->logger->error('Could not seal the account closure record.', ['userId' => $summary->userId, 'exception' => $e]);
            $this->auditLogger->log(
                action: 'account.closure_record_failed',
                actor: $user,
                payload: ['error' => $e::class],
                subjectType: 'User',
                subjectId: $summary->userId,
                severity: AuditSeverity::Warning,
            );

            return [null, null];
        }
    }

    /** base64 of the KEK re-wrapped under the archive key. The KEK itself is unchanged. */
    private function archivedKek(User $user): string
    {
        $kek = $this->keyManagement->userKek($user);
        try {
            return base64_encode($this->archive->wrapKek($kek, self::archiveAad($user)));
        } finally {
            sodium_memzero($kek);
        }
    }

    public static function archiveAad(User $user): string
    {
        return 'archived-kek:'.$user->getId()->toRfc4122();
    }
}
