<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\DocumentVersion;
use App\Document\Repository\DocumentKeyGrantRepository;

/**
 * Decrypts a document version's bytes for a user who holds a grant on it
 * (ADR-004). Access is gated by the presence of a DocumentKeyGrant - no grant,
 * no key, no plaintext - and every successful access is audited. The DEK is
 * wiped as soon as decryption finishes.
 */
final class DocumentDownloader
{
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly KeyManagementService $keys,
        private readonly DocumentStorageInterface $storage,
        private readonly DocumentKeyGrantRepository $grants,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @return string decrypted plaintext bytes
     *
     * @throws DomainException if the user has no grant, or on any crypto/storage failure
     */
    public function download(DocumentVersion $version, User $user): string
    {
        $grant = $this->grants->findForVersionAndUser($version, $user)
            ?? throw new DomainException('You do not have access to this document.');

        $dek = $this->keys->unwrapDek($user, $grant->getWrappedDek(), $version->dekAad());
        try {
            $ciphertext = $this->storage->retrieve($version->getStorageKey());
            $plaintext = $this->encryption->decrypt($ciphertext, $dek, $version->dekAad());
        } finally {
            sodium_memzero($dek);
        }

        $this->auditLogger->log(
            action: 'document.accessed',
            actor: $user,
            payload: ['versionNumber' => $version->getVersionNumber()],
            subjectType: 'DocumentVersion',
            subjectId: $version->getId()->toRfc4122(),
        );

        return $plaintext;
    }
}
