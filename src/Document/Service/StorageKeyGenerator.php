<?php

declare(strict_types=1);

namespace App\Document\Service;

/**
 * Generates opaque storage keys for ciphertext blobs. A key is 32 random hex
 * chars, prefixed by its first two chars as a fan-out directory:
 * "ab/abXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX". Never derived from user input, so it
 * leaks nothing about the document and cannot collide meaningfully.
 */
final class StorageKeyGenerator
{
    public function generate(): string
    {
        $hex = bin2hex(random_bytes(16)); // 32 hex chars

        return substr($hex, 0, 2).'/'.$hex;
    }
}
