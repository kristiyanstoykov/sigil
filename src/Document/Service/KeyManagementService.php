<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Crypto\RootKeyWrapperInterface;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Repository\UserEncryptionKeyRepository;

/**
 * Operates the middle layer of the envelope (ADR-004): resolves a user's KEK
 * (unwrapping it via the root-key wrapper, or minting one on first use) and
 * uses it to wrap/unwrap per-file DEKs.
 *
 * The root layer is reached only through {@see RootKeyWrapperInterface} - the
 * one seam where the root key is used (ADR-010) - so this service is unaware of
 * whether the root key lives in env memory or inside a PKCS#11 token.
 *
 * Raw keys returned here exist only transiently in memory. The KEK is wiped
 * with sodium_memzero() as soon as an operation finishes; DEKs returned to
 * callers are the caller's to wipe once used.
 */
final class KeyManagementService
{
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly RootKeyWrapperInterface $rootWrapper,
        private readonly UserEncryptionKeyRepository $keys,
    ) {
    }

    /**
     * The user's raw 32-byte KEK. Created (wrapped by the root key) on first
     * call, unwrapped from storage thereafter. The create path is safe against
     * a concurrent first-use: whichever request inserts first wins, and both
     * end up unwrapping the same stored KEK.
     */
    public function userKek(User $user): string
    {
        $record = $this->keys->findForUser($user);

        if (null === $record) {
            $kek = $this->encryption->generateKey();
            $wrapped = base64_encode(
                $this->rootWrapper->wrapKek($kek, $this->kekAad($user)),
            );
            sodium_memzero($kek);

            // ON CONFLICT DO NOTHING: a racing request may have inserted first;
            // either way the row now exists and we unwrap the winner below.
            $this->keys->insertIfAbsent($user, $wrapped);
            $record = $this->keys->findForUser($user)
                ?? throw new DomainException('User encryption key could not be created.');
        }

        return $this->rootWrapper->unwrapKek(
            self::decode($record->getWrappedKek()),
            $this->kekAad($user),
        );
    }

    /**
     * Wrap a raw DEK under $user's KEK, returning base64 for storage in a
     * DocumentKeyGrant. $aad binds the wrap to its context (the file-version id).
     */
    public function wrapDek(User $user, string $dek, string $aad = ''): string
    {
        $kek = $this->userKek($user);
        try {
            return base64_encode($this->encryption->encrypt($dek, $kek, $this->dekAad($user, $aad)));
        } finally {
            sodium_memzero($kek);
        }
    }

    /**
     * Unwrap a DEK previously wrapped for $user. $aad must match the value used
     * when wrapping.
     *
     * @throws \App\Core\Crypto\Exception\DecryptionFailedException
     */
    public function unwrapDek(User $user, string $wrappedDek, string $aad = ''): string
    {
        $kek = $this->userKek($user);
        try {
            return $this->encryption->decrypt(self::decode($wrappedDek), $kek, $this->dekAad($user, $aad));
        } finally {
            sodium_memzero($kek);
        }
    }

    /** Bind the KEK envelope to its owner so a KEK row can't be swapped between users. */
    private function kekAad(User $user): string
    {
        return 'kek:'.$user->getId()->toRfc4122();
    }

    /**
     * Bind a DEK wrap to BOTH the grantee and the caller context (version id).
     * Per-user KEK isolation already prevents cross-user reuse; binding the user
     * here is cheap defence-in-depth on top.
     */
    private function dekAad(User $user, string $callerAad): string
    {
        return 'grant:'.$user->getId()->toRfc4122().'|'.$callerAad;
    }

    private static function decode(string $base64): string
    {
        return base64_decode($base64, true) ?: '';
    }
}
