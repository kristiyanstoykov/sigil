<?php

declare(strict_types=1);

namespace App\Core\Crypto;

/**
 * Wraps and unwraps the middle-layer KEK against the application root key
 * (top of the ADR-004 envelope). This is the ONE seam where the root key is
 * used - isolating it here is what lets the root key move out of the app's
 * memory and into a PKCS#11 token / HSM (ADR-010) without touching the rest
 * of the key hierarchy.
 *
 * Implementations return and accept RAW bytes; the caller
 * ({@see \App\Document\Service\KeyManagementService}) base64s for storage.
 * The wrapped output is self-describing: a leading scheme byte distinguishes
 * an in-app envelope ({@see EnvRootKeyWrapper}, 0x01) from a token-wrapped
 * blob ({@see Pkcs11RootKeyWrapper}, 0x02), so the two can coexist during
 * migration and a wrapper refuses a blob it did not produce.
 */
interface RootKeyWrapperInterface
{
    /**
     * Wrap a raw KEK, returning raw wrapped bytes.
     *
     * @param string $rawKek raw 32-byte KEK (never persisted in this form)
     * @param string $aad    context bound into the wrap (the KEK's owner id),
     *                        required identically to unwrap
     */
    public function wrapKek(#[\SensitiveParameter] string $rawKek, string $aad): string;

    /**
     * Unwrap a previously wrapped KEK, returning the raw 32-byte KEK.
     *
     * @throws \App\Core\Crypto\Exception\DecryptionFailedException on tampering, wrong key, or a foreign scheme
     */
    public function unwrapKek(string $wrappedKek, string $aad): string;
}
