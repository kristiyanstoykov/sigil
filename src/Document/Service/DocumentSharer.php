<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentKeyGrant;
use App\Document\Entity\DocumentVersion;
use App\Document\Repository\DocumentKeyGrantRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sharing and revoking, the ADR-004 way: re-wrap, never re-encrypt.
 *
 * To share, the owner's wrapped DEK for each version is unwrapped under the
 * owner's KEK and re-wrapped under the recipient's, producing one extra
 * {@see DocumentKeyGrant} row per version. The stored ciphertext is never read,
 * never rewritten, and no DEK ever changes - so sharing a 10 MB file costs a
 * handful of AEAD operations on 32-byte keys, not a re-encryption.
 *
 * To revoke, the grant rows are deleted. There is then no copy of that version's
 * DEK wrapped for the revoked user, and the root->KEK->DEK hierarchy gives them
 * no other route to one.
 *
 * The grants are the access list; future versions inherit it in
 * {@see DocumentVersionWriter}. Raw DEKs live in memory only for the moment
 * between unwrap and re-wrap, and are wiped immediately after.
 */
final class DocumentSharer
{
    public function __construct(
        private readonly KeyManagementService $keys,
        private readonly DocumentKeyGrantRepository $grants,
        private readonly DocumentNotifier $notifier,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Give $recipient read access to every version of $document.
     *
     * @throws DomainException if $actor is not the owner, the recipient is the
     *                         owner, they already have access, or the actor
     *                         somehow cannot read a version themselves
     */
    public function share(Document $document, User $actor, User $recipient): void
    {
        $this->assertOwner($document, $actor);

        if (self::isSameUser($recipient, $document->getOwner())) {
            throw new DomainException('You already have access to this document.');
        }

        if ($this->grants->hasGrantForDocument($document, $recipient)) {
            throw new DomainException('This document is already shared with that person.');
        }

        foreach ($document->getVersions() as $version) {
            $ownerGrant = $this->grants->findForVersionAndUser($version, $actor)
                ?? throw new DomainException('You do not have access to every version of this document.');

            $dek = $this->keys->unwrapDek($actor, $ownerGrant->getWrappedDek(), $version->dekAad());
            try {
                $this->em->persist(new DocumentKeyGrant(
                    $version,
                    $recipient,
                    $this->keys->wrapDek($recipient, $dek, $version->dekAad()),
                ));
            } finally {
                sodium_memzero($dek);
            }
        }

        $this->em->flush();

        $this->auditLogger->log(
            action: 'document.shared',
            actor: $actor,
            payload: [
                'recipientId' => $recipient->getId()->toRfc4122(),
                'recipientEmail' => $recipient->getEmail(),
                'versions' => \count($document->getVersions()),
            ],
            subjectType: 'Document',
            subjectId: $document->getId()->toRfc4122(),
        );

        $this->notifier->notifyShared($document, $recipient, $actor);
    }

    /**
     * Give $recipient read access to a single version, re-wrapping that version's
     * DEK out of $actor's own grant. Used by the signing queue, which hands access
     * over one turn at a time instead of sharing the whole document up front.
     *
     * The authority check belongs to the caller: $actor only needs to be able to
     * read the version, which is not the same as being allowed to hand it on.
     *
     * @throws DomainException if $actor cannot read this version
     */
    public function grantVersion(DocumentVersion $version, User $actor, User $recipient): void
    {
        if (null !== $this->grants->findForVersionAndUser($version, $recipient)) {
            return;
        }

        $actorGrant = $this->grants->findForVersionAndUser($version, $actor)
            ?? throw new DomainException('You do not have access to this version.');

        $dek = $this->keys->unwrapDek($actor, $actorGrant->getWrappedDek(), $version->dekAad());
        try {
            $this->em->persist(new DocumentKeyGrant(
                $version,
                $recipient,
                $this->keys->wrapDek($recipient, $dek, $version->dekAad()),
            ));
            $this->em->flush();
        } finally {
            sodium_memzero($dek);
        }

        $this->auditLogger->log(
            action: 'document.version_access_granted',
            actor: $actor,
            payload: [
                'recipientId' => $recipient->getId()->toRfc4122(),
                'recipientEmail' => $recipient->getEmail(),
                'versionNumber' => $version->getVersionNumber(),
            ],
            subjectType: 'Document',
            subjectId: $version->getDocument()->getId()->toRfc4122(),
        );
    }

    /**
     * Take $recipient's access away again - every version at once, so no stale
     * grant on an old version keeps a door open.
     *
     * @throws DomainException if $actor is not the owner, or the target is the owner
     */
    public function revoke(Document $document, User $actor, User $recipient): void
    {
        $this->assertOwner($document, $actor);

        if (self::isSameUser($recipient, $document->getOwner())) {
            throw new DomainException("The owner's own access cannot be revoked.");
        }

        $deleted = $this->grants->deleteForDocumentAndUser($document, $recipient);

        if (0 === $deleted) {
            throw new DomainException('That person does not have access to this document.');
        }

        $this->auditLogger->log(
            action: 'document.share_revoked',
            actor: $actor,
            payload: [
                'recipientId' => $recipient->getId()->toRfc4122(),
                'recipientEmail' => $recipient->getEmail(),
                'grantsDeleted' => $deleted,
            ],
            subjectType: 'Document',
            subjectId: $document->getId()->toRfc4122(),
        );
    }

    /**
     * Sharing is the owner's call alone: a recipient cannot pass access on.
     * Enforced here rather than only in the controller, so every caller is bound
     * by it.
     */
    private function assertOwner(Document $document, User $actor): void
    {
        if (!self::isSameUser($actor, $document->getOwner())) {
            throw new DomainException('Only the owner can share this document.');
        }
    }

    private static function isSameUser(User $a, User $b): bool
    {
        return $a->getId()->toRfc4122() === $b->getId()->toRfc4122();
    }
}
