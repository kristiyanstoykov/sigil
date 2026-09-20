<?php

declare(strict_types=1);

namespace App\Certificate\Algorithm;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One implementation per supported signature suite (ADR-006, ADR-014). The
 * suite is a deployment setting (SIGIL_SIGNATURE_ACTIVE_ALGORITHM) stamped on
 * each Certificate by its stable id - never a user choice. Adding a suite is
 * adding a class; the Python drivers dispatch on {@see toDriverSpec()}, so the
 * choice travels the whole way to the token.
 */
#[AutoconfigureTag('app.signature_algorithm')]
interface SignatureAlgorithmInterface
{
    /**
     * Stable, versioned identifier persisted on Certificate.algorithmId,
     * e.g. "ECDSA-P384-SHA384/v1". Never reuse or repurpose an id.
     */
    public function id(): string;

    /** Key family the drivers dispatch on: "ecdsa" | "ml-dsa". */
    public function family(): string;

    /** Curve or parameter set within the family: "secp384r1", "ML-DSA-65". */
    public function parameterSet(): string;

    /**
     * Content digest: the TBS hash for a prehash suite, the CMS messageDigest
     * attribute for either. asn1crypto name, e.g. "sha384".
     */
    public function digest(): string;

    public function signingMode(): SigningMode;

    /**
     * Signature algorithm as asn1crypto/pyHanko name it, for both the X.509
     * signatureAlgorithm and the CMS SignerInfo: "sha384_ecdsa", "mldsa65".
     */
    public function signatureAlgorithm(): string;

    /** Human-readable label for UI/cert detail pages. */
    public function label(): string;

    /** Short, filesystem- and token-label-safe slug: "ecdsa-p384", "ml-dsa-65". */
    public function slug(): string;

    /**
     * The contract with bin/*.py: everything a driver needs to generate the
     * key, build the certificate and sign, versioned so a driver can refuse a
     * shape it does not know.
     *
     * @return array{spec: 'v1', id: string, family: string, parameter_set: string, digest: string, signing_mode: string, signature_algorithm: string}
     */
    public function toDriverSpec(): array;
}
