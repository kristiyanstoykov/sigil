<?php

declare(strict_types=1);

namespace App\Receipt\Service;

use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Service\SuiteCredentials;
use App\Core\Exception\DomainException;
use App\Signing\Service\PadesSignerInterface;
use App\Signing\Service\PadesSignRequest;
use App\Signing\Service\TsaProviderRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Applies Sigil's own PAdES seal to a rendered receipt (ADR-012).
 *
 * This is a SEAL, not a signature: it speaks for the application, not for any
 * person, so its certificate carries digital_signature without non_repudiation
 * and its PIN comes from the environment rather than from a human. It is the
 * one credential Sigil can use unattended, and it can only ever seal receipts -
 * a user's document is never signed with it.
 */
final class ReceiptSealer
{
    private const string KEY_LABEL = 'sign';

    public function __construct(
        private readonly PadesSignerInterface $signer,
        private readonly TsaProviderRegistry $tsa,
        private readonly SignatureAlgorithmRegistry $algorithms,
        private readonly SuiteCredentials $credentials,
        #[Autowire(env: 'SIGIL_SEAL_PIN')]
        private readonly string $sealPin,
    ) {
    }

    /** Whether the active suite's seal and its CA are provisioned. */
    public function isReady(): bool
    {
        $suite = $this->algorithms->active();

        return is_file($this->credentials->sealCertPath($suite)) && is_file($this->credentials->caCertPath($suite));
    }

    /**
     * @return array{bytes: string, serialNumber: string}
     *
     * @throws DomainException if the seal is not initialized or sealing fails
     */
    public function seal(string $pdfBytes, string $documentTitle): array
    {
        if (!$this->isReady()) {
            throw new DomainException('The delivery seal is not initialized (run sigil:seal:init).');
        }

        // Receipts are sealed with the active suite's seal (sigil:seal:init
        // provisions one per suite); a receipt embeds its chain, so those
        // sealed under an earlier suite keep validating.
        $suite = $this->algorithms->active();
        $sealPem = (string) file_get_contents($this->credentials->sealCertPath($suite));

        $request = new PadesSignRequest(
            pdfBytes: $pdfBytes,
            tokenLabel: $this->credentials->sealTokenLabel($suite),
            keyLabel: self::KEY_LABEL,
            signingCertPem: $sealPem,
            caChainPem: (string) file_get_contents($this->credentials->caCertPath($suite)),
            signerName: 'SIGIL SIGNUM VERITATIS',
            algorithmId: $suite->id(),
            tsaUrl: $this->tsa->activeUrl(),
            reason: sprintf('Delivery receipt for "%s"', $documentTitle),
            fieldName: 'SigilSeal-'.bin2hex(random_bytes(4)),
            // Not "Qualified electronic signature": this is a seal by the
            // application, and Sigil is not a qualified trust service provider.
            appearanceLine1: 'Electronic seal (delivery receipt)',
        );

        return [
            'bytes' => $this->signer->sign($request, $this->sealPin),
            'serialNumber' => self::serialOf($sealPem),
        ];
    }

    /** Serial of the seal certificate, so a reader knows which key to verify against. */
    private static function serialOf(string $pem): string
    {
        $parsed = openssl_x509_parse($pem);
        if (false === $parsed || !\is_string($parsed['serialNumberHex'] ?? null)) {
            throw new DomainException('The seal certificate could not be read.');
        }

        return $parsed['serialNumberHex'];
    }
}
