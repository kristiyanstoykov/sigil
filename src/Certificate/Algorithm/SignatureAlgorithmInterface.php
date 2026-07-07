<?php

declare(strict_types=1);

namespace App\Certificate\Algorithm;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One implementation per supported signature suite (ADR-006). The algorithm
 * is a fixed strong default stored on the Certificate by its stable id -
 * never a user setting. Adding a suite = adding a class; nothing else changes.
 */
#[AutoconfigureTag('app.signature_algorithm')]
interface SignatureAlgorithmInterface
{
    /**
     * Stable, versioned identifier persisted on Certificate.algorithmId,
     * e.g. "ECDSA-P384-SHA384/v1". Never reuse or repurpose an id.
     */
    public function id(): string;

    /** Key spec as understood by pkcs11-tool --keypairgen --key-type, e.g. "EC:secp384r1". */
    public function pkcs11KeyType(): string;

    /** Digest for TBS/document hashing, e.g. "sha384". */
    public function digest(): string;

    /**
     * Signature algorithm name as understood by asn1crypto/pyHanko
     * (SignedDigestAlgorithm), e.g. "sha384_ecdsa".
     */
    public function x509SignatureAlgorithm(): string;

    /** Human-readable label for UI/cert detail pages. */
    public function label(): string;
}
