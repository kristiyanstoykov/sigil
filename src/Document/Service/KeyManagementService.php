<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Crypto\RootKeyProvider;
use App\Core\Entity\User;
use App\Document\Entity\UserEncryptionKey;
use App\Document\Repository\UserEncryptionKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Operates the middle layer of the envelope (ADR-004): resolves a user's KEK
 * (unwrapping it from the root key, or minting one on first use) and uses it to
 * wrap/unwrap per-file DEKs.
 *
 * Raw keys returned here exist only transiently in memory - callers must never
 * persist or log them. Only wrapped forms are stored.
 */
final class KeyManagementService
{
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly RootKeyProvider $rootKeys,
        private readonly UserEncryptionKeyRepository $keys,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The user's raw 32-byte KEK. Created and persisted (wrapped by the root
     * key) on first call, unwrapped from storage thereafter.
     */
    public function userKek(User $user): string
    {
        $record = $this->keys->findForUser($user);
        if (null !== $record) {
            return $this->encryption->decrypt(
                self::decode($record->getWrappedKek()),
                $this->rootKeys->rootKey(),
                $this->kekAad($user),
            );
        }

        $kek = $this->encryption->generateKey();
        $wrapped = $this->encryption->encrypt($kek, $this->rootKeys->rootKey(), $this->kekAad($user));

        $record = new UserEncryptionKey($user, base64_encode($wrapped));
        $this->em->persist($record);
        $this->em->flush();

        return $kek;
    }

    /**
     * Wrap a raw DEK under $user's KEK, returning base64 for storage in a
     * DocumentKeyGrant. $aad binds the wrap to its context (the file-version id).
     */
    public function wrapDek(User $user, string $dek, string $aad = ''): string
    {
        return base64_encode($this->encryption->encrypt($dek, $this->userKek($user), $aad));
    }

    /**
     * Unwrap a DEK previously wrapped for $user. $aad must match the value used
     * when wrapping.
     *
     * @throws \App\Core\Crypto\Exception\DecryptionFailedException
     */
    public function unwrapDek(User $user, string $wrappedDek, string $aad = ''): string
    {
        return $this->encryption->decrypt(self::decode($wrappedDek), $this->userKek($user), $aad);
    }

    /** Bind the KEK envelope to its owner so a KEK row can't be swapped between users. */
    private function kekAad(User $user): string
    {
        return 'kek:'.$user->getId()->toRfc4122();
    }

    private static function decode(string $base64): string
    {
        return base64_decode($base64, true) ?: '';
    }
}
