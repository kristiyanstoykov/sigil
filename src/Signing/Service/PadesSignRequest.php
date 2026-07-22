<?php

declare(strict_types=1);

namespace App\Signing\Service;

/**
 * Everything a {@see PadesSignerInterface} needs to sign a PDF, EXCEPT the PIN -
 * the PIN is passed separately to {@see PadesSignerInterface::sign()} so it is
 * never held on a value object (ADR-007: PIN present only in-request).
 *
 * The signing certificate travels as PEM (the DB is the source of truth -
 * Certificate::certificatePem), not looked up inside the token.
 */
final readonly class PadesSignRequest
{
    public function __construct(
        public string $pdfBytes,
        public string $tokenLabel,
        public string $keyLabel,
        public string $signingCertPem,
        public string $caChainPem,
        public string $signerName,
        public ?string $tsaUrl = null,
        public ?string $reason = null,
        public ?string $location = null,
        public int $page = -1,
        public string $fieldName = 'Signature1',
    ) {
    }
}
