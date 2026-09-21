<?php

declare(strict_types=1);

namespace App\AuditLog\Service;

/**
 * The one place the chain hash is spelled. Each entry records the scheme it
 * was hashed under, so the verifier recomputes every entry with the scheme it
 * names rather than the one this build defaults to - a future scheme can be
 * introduced without rehashing (and thereby rewriting) the past.
 */
final class AuditChainHasher
{
    /** The scheme new entries are written with. */
    public const string SCHEME = 'SHA256/v1';

    public static function hash(string $scheme, string $previousHash, string $canonicalPayload): string
    {
        return match ($scheme) {
            self::SCHEME => hash('sha256', $previousHash.$canonicalPayload),
            default => throw new \InvalidArgumentException(sprintf('Unknown audit chain scheme "%s".', $scheme)),
        };
    }
}
