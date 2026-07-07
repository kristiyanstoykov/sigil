<?php

declare(strict_types=1);

namespace App\Certificate\Algorithm;

/**
 * Compatibility suite (ADR-006): RSA-3072 + SHA-384.
 */
final class Rsa3072Sha384 implements SignatureAlgorithmInterface
{
    public const string ID = 'RSA-3072-SHA384/v1';

    public function id(): string
    {
        return self::ID;
    }

    public function pkcs11KeyType(): string
    {
        return 'rsa:3072';
    }

    public function digest(): string
    {
        return 'sha384';
    }

    public function x509SignatureAlgorithm(): string
    {
        return 'sha384_rsa';
    }

    public function label(): string
    {
        return 'RSA-3072 with SHA-384';
    }
}
