<?php

declare(strict_types=1);

namespace App\Certificate\Algorithm;

/**
 * Post-quantum suite (ADR-014): ML-DSA-65 (FIPS 204), X.509 per RFC 9881, CMS
 * per RFC 9882. Pure mode: the token signs the full message; SHA-384 is only
 * the CMS content digest, which RFC 9882 allows for ML-DSA-65.
 */
final class MlDsa65 implements SignatureAlgorithmInterface
{
    use DriverSpecTrait;

    public const string ID = 'ML-DSA-65/v1';

    public function id(): string
    {
        return self::ID;
    }

    public function family(): string
    {
        return 'ml-dsa';
    }

    public function parameterSet(): string
    {
        return 'ML-DSA-65';
    }

    public function digest(): string
    {
        return 'sha384';
    }

    public function signingMode(): SigningMode
    {
        return SigningMode::Pure;
    }

    public function signatureAlgorithm(): string
    {
        return 'mldsa65';
    }

    public function label(): string
    {
        return 'ML-DSA-65 (post-quantum, FIPS 204)';
    }

    public function slug(): string
    {
        return 'ml-dsa-65';
    }
}
