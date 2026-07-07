<?php

declare(strict_types=1);

namespace App\Certificate\Algorithm;

/**
 * Default suite (ADR-006): ECDSA P-384 + SHA-384.
 */
final class EcdsaP384Sha384 implements SignatureAlgorithmInterface
{
    public const string ID = 'ECDSA-P384-SHA384/v1';

    public function id(): string
    {
        return self::ID;
    }

    public function pkcs11KeyType(): string
    {
        return 'EC:secp384r1';
    }

    public function digest(): string
    {
        return 'sha384';
    }

    public function x509SignatureAlgorithm(): string
    {
        return 'sha384_ecdsa';
    }

    public function label(): string
    {
        return 'ECDSA P-384 with SHA-384';
    }
}
