<?php

declare(strict_types=1);

namespace App\Core\Crypto\Exception;

use App\Core\Exception\DomainException;

/**
 * Thrown whenever AEAD verification fails: wrong key, tampered ciphertext,
 * tampered algo-id/nonce, or a malformed envelope. The message is deliberately
 * generic - never leak which part failed.
 */
final class DecryptionFailedException extends DomainException
{
    public function __construct(string $message = 'Decryption failed.', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
