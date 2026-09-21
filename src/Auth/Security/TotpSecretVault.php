<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Crypto\Exception\DecryptionFailedException;
use App\Core\Crypto\RootKeyProvider;

/**
 * The TOTP seed at rest. A leaked users table must not hand out second
 * factors, so the column holds an envelope under a root-derived key (ADR-004
 * style) rather than the base32 seed, and only this class opens it. Nothing
 * else in the application - the entity, the session, the bundle - ever sees
 * the plaintext except through {@see SealedGoogleAuthenticator} at code-check time.
 */
final class TotpSecretVault
{
    /** Marks a sealed value; a bare base32 seed (rows from before 2026-09-21) has no prefix. */
    public const string PREFIX = 'sealed:';

    private const KEY_CONTEXT = 'sigil:totp-secret/v1';

    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly RootKeyProvider $rootKeys,
    ) {
    }

    public function seal(#[\SensitiveParameter] string $secret, string $userId): string
    {
        $key = $this->key();
        try {
            return self::PREFIX.base64_encode($this->encryption->encrypt($secret, $key, $this->aad($userId)));
        } finally {
            sodium_memzero($key);
        }
    }

    /**
     * @throws DecryptionFailedException on a sealed value that does not open (tampering, wrong key, wrong user)
     */
    public function open(string $stored, string $userId): string
    {
        if (!self::isSealed($stored)) {
            return $stored; // legacy plaintext row; sigil:totp:seal moves it over
        }

        $envelope = base64_decode(substr($stored, \strlen(self::PREFIX)), true);
        if (false === $envelope) {
            throw new DecryptionFailedException();
        }

        $key = $this->key();
        try {
            return $this->encryption->decrypt($envelope, $key, $this->aad($userId));
        } finally {
            sodium_memzero($key);
        }
    }

    public static function isSealed(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    private function key(): string
    {
        return $this->encryption->deriveKey($this->rootKeys->rootKey(), self::KEY_CONTEXT);
    }

    /** Bound to the user, so a sealed seed copied onto another row does not open. */
    private function aad(string $userId): string
    {
        return 'totp:'.$userId;
    }
}
