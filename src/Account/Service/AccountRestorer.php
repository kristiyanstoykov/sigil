<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Repository\ArchivedUserKeyRepository;
use App\AuditLog\AuditLoggerInterface;
use App\Auth\Security\EmailFingerprinter;
use App\Certificate\Service\PinHasher;
use App\Core\Doctrine\RowLock;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Service\KeyManagementService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Brings a deleted account back on its own id (ADR-015 §4): the archived KEK
 * returns under the root wrapper, so every grant, document and history row is
 * as it was. Certificates do not return; they were revoked. Proving control of
 * the mailbox is the caller's job.
 */
final class AccountRestorer
{
    public const string WRONG_CODE = 'That recovery code is not correct.';

    public function __construct(
        private readonly ArchivedUserKeyRepository $archivedKeys,
        private readonly ArchiveKeyWrapper $archive,
        private readonly KeyManagementService $keys,
        private readonly EmailFingerprinter $fingerprinter,
        private readonly PinHasher $hasher,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Whether $email is the address the deleted account had. */
    public function belongsTo(User $user, string $email): bool
    {
        return $user->isDeleted()
            && null !== $user->getEmailFingerprint()
            && hash_equals($user->getEmailFingerprint(), $this->fingerprinter->fingerprint($email));
    }

    /**
     * @param non-empty-string $email
     *
     * @throws DomainException on a wrong code, or an account that is no longer deleted under that address
     */
    public function restore(
        User $user,
        string $email,
        #[\SensitiveParameter] string $recoveryCode,
        string $firstName,
        string $lastName,
        ?string $company,
        ?string $position,
        string $passwordHash,
    ): void {
        if (!$this->belongsTo($user, $email)) {
            throw new DomainException('This account cannot be restored with that address.');
        }
        if (!$this->hasher->verify(RecoveryCode::normalize($recoveryCode), (string) $user->getRecoveryCodeHash())) {
            throw new DomainException(self::WRONG_CODE);
        }

        // The token step runs before the transaction, as on deletion. No archived
        // key means a purged account (ADR-015 §6): it comes back empty.
        $archived = $this->archivedKeys->findForUser($user);
        $kek = null !== $archived
            ? $this->archive->unwrapKek((string) base64_decode($archived->getWrappedKek(), true), AccountDeleter::archiveAad($user))
            : null;

        try {
            $this->em->wrapInTransaction(function () use ($user, $email, $kek, $firstName, $lastName, $company, $position, $passwordHash): void {
                RowLock::acquire($this->em, $user);
                if (!$this->belongsTo($user, $email)) {
                    throw new DomainException('This account has already been restored.');
                }
                $archived = $this->archivedKeys->findForUser($user);
                if ((null === $archived) !== (null === $kek)) {
                    throw new DomainException('Something changed while your account was being restored. Please try again.');
                }

                $user->restore($email, $firstName, $lastName, $company, $position, $passwordHash);
                $this->em->flush();

                if (null !== $archived && null !== $kek) {
                    $this->keys->adoptKek($user, $kek);
                    $this->em->remove($archived);
                    $this->em->flush();
                }

                $this->auditLogger->log(
                    action: 'account.restored',
                    actor: $user,
                    payload: ['keyRestored' => null !== $kek],
                    subjectType: 'User',
                    subjectId: $user->getId()->toRfc4122(),
                );
            });
        } finally {
            if (null !== $kek) {
                sodium_memzero($kek);
            }
        }
    }
}
