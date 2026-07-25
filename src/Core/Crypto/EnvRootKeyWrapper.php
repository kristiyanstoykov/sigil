<?php

declare(strict_types=1);

namespace App\Core\Crypto;

/**
 * Root-key wrapper backed by the env-supplied root key (the original ADR-004
 * behaviour). The root key sits in application memory and the wrap is an
 * in-process AES-256-GCM envelope via {@see EncryptionServiceInterface}.
 *
 * Kept for two reasons:
 *  - the test environment binds {@see RootKeyWrapperInterface} to this, so the
 *    suite needs no PKCS#11 token;
 *  - it is the SOURCE side of the one-off migration to the token wrapper
 *    (ADR-010) - `sigil:root-key:migrate` unwraps with this, re-wraps with
 *    {@see Pkcs11RootKeyWrapper}.
 *
 * Its output starts with the envelope format byte 0x01, so it is byte-identical
 * to KEKs already stored before ADR-010 and distinguishable from the token
 * wrapper's 0x02 blobs.
 */
final class EnvRootKeyWrapper implements RootKeyWrapperInterface
{
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly RootKeyProvider $rootKeys,
    ) {
    }

    public function wrapKek(#[\SensitiveParameter] string $rawKek, string $aad): string
    {
        return $this->encryption->encrypt($rawKek, $this->rootKeys->rootKey(), $aad);
    }

    public function unwrapKek(string $wrappedKek, string $aad): string
    {
        return $this->encryption->decrypt($wrappedKek, $this->rootKeys->rootKey(), $aad);
    }
}
