<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use App\Core\Crypto\Exception\DecryptionFailedException;

/**
 * Default {@see EncryptionServiceInterface}. Owns the envelope framing and
 * binds the format version + algo_id into the AEAD associated data so neither
 * can be altered without failing authentication.
 *
 * Envelope layout (all binary, no base64):
 *
 *   byte  0        format version (0x01)
 *   byte  1        uint8 length of algo_id
 *   bytes 2..      algo_id (ASCII, e.g. "AES-256-GCM/v1")
 *   next  N        nonce (suite-defined length)
 *   rest           ciphertext with tag appended
 *
 * Bound AAD = version-byte ‖ algo_id ‖ caller-aad. Flipping the version or
 * algo_id (e.g. to point at a weaker suite) changes the AAD and the GCM tag
 * check fails - the downgrade is rejected, not silently honoured.
 */
final class EnvelopeEncryptionService implements EncryptionServiceInterface
{
    private const FORMAT_VERSION = 0x01;

    public function __construct(
        private readonly CipherAlgorithmRegistry $registry,
    ) {
    }

    public function encrypt(string $plaintext, string $key, string $aad = ''): string
    {
        $cipher = $this->registry->default();
        $algoId = $cipher->id();

        $header = \chr(self::FORMAT_VERSION).\chr(\strlen($algoId)).$algoId;
        $nonce = random_bytes($cipher->nonceLength());
        $ciphertext = $cipher->encrypt($plaintext, $nonce, $key, $this->bindAad($algoId, $aad));

        return $header.$nonce.$ciphertext;
    }

    public function decrypt(string $envelope, string $key, string $aad = ''): string
    {
        // Header: version + algo-id-length + algo-id.
        if (\strlen($envelope) < 2 || self::FORMAT_VERSION !== \ord($envelope[0])) {
            throw new DecryptionFailedException();
        }

        $algoIdLen = \ord($envelope[1]);
        $offset = 2;
        if (\strlen($envelope) < $offset + $algoIdLen) {
            throw new DecryptionFailedException();
        }

        $algoId = substr($envelope, $offset, $algoIdLen);
        $offset += $algoIdLen;

        $cipher = $this->registry->get($algoId);
        $nonceLen = $cipher->nonceLength();
        if (\strlen($envelope) < $offset + $nonceLen) {
            throw new DecryptionFailedException();
        }

        $nonce = substr($envelope, $offset, $nonceLen);
        $ciphertext = substr($envelope, $offset + $nonceLen);

        return $cipher->decrypt($ciphertext, $nonce, $key, $this->bindAad($algoId, $aad));
    }

    public function generateKey(): string
    {
        return random_bytes($this->registry->default()->keyLength());
    }

    /** Bind the framing that must not be tampered with into the AEAD AAD. */
    private function bindAad(string $algoId, string $callerAad): string
    {
        return \chr(self::FORMAT_VERSION).$algoId."\0".$callerAad;
    }
}
