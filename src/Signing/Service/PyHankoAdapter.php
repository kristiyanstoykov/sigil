<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Signing\Exception\SigningException;
use App\Signing\Exception\TokenPinRejectedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * {@see PadesSignerInterface} backed by bin/sign_pdf.py over native PKCS#11.
 * Mirrors {@see \App\Certificate\Service\CertificateIssuer::runDriver}: JSON on
 * stdin (PIN never on argv/disk), JSON on stdout, and on failure the driver
 * reports only the exception *type* - which we audit and then surface as a
 * generic message, so a PIN can never leak into a log or the UI.
 */
final class PyHankoAdapter implements PadesSignerInterface
{
    /**
     * python-pkcs11 error type names the driver reports when the TOKEN itself
     * rejected the PIN (CKR_PIN_INCORRECT / CKR_PIN_LOCKED). Reaching these
     * despite the hash gate means hash⇄token desync (ADR-008).
     */
    private const array TOKEN_PIN_REJECTIONS = ['PinIncorrect', 'PinLocked'];

    public function __construct(
        private readonly AuditLoggerInterface $auditLogger,
        #[Autowire(env: 'PKCS11_MODULE')]
        private readonly string $modulePath,
        #[Autowire('%kernel.project_dir%/bin/sign_pdf.py')]
        private readonly string $driverPath,
    ) {
    }

    public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
    {
        $result = $this->runDriver([
            'module' => $this->modulePath,
            'pdf_b64' => base64_encode($request->pdfBytes),
            'signer' => [
                'token_label' => $request->tokenLabel,
                'key_label' => $request->keyLabel,
                'signing_cert_pem' => $request->signingCertPem,
                'pin' => $pin,
            ],
            'ca_chain_pem' => $request->caChainPem,
            'field_name' => $request->fieldName,
            'reason' => $request->reason,
            'location' => $request->location,
            'tsa_url' => $request->tsaUrl,
            'appearance' => array_filter([
                'signer_name' => $request->signerName,
                'line1' => $request->appearanceLine1,
            ], static fn (?string $value): bool => null !== $value),
            'page' => $request->page,
        ]);

        $pdf = base64_decode($result['pdf_b64'], true);
        if (false === $pdf || '' === $pdf) {
            throw new SigningException('Document signing failed.');
        }

        return $pdf;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{pdf_b64: string}
     */
    private function runDriver(array $request): array
    {
        $process = new Process(['python3', $this->driverPath]);
        $process->setInput(json_encode($request, \JSON_THROW_ON_ERROR));
        $process->setTimeout(120); // a TSA round-trip can be slow
        $process->run();

        /** @var mixed $decoded */
        $decoded = json_decode($process->getOutput(), true);

        if (!\is_array($decoded) || true !== ($decoded['ok'] ?? false)) {
            $error = \is_array($decoded) && \is_string($decoded['error'] ?? null)
                ? $decoded['error']
                : 'driver produced no output';

            // A token PIN rejection is handled (and audited) by the caller via
            // the ADR-008 desync path; don't double-audit it as a signing fault.
            if (\in_array($error, self::TOKEN_PIN_REJECTIONS, true)) {
                throw new TokenPinRejectedException('The token rejected the PIN.');
            }

            $this->auditLogger->log(
                action: 'document.signing_failed',
                payload: ['error' => $error],
                severity: AuditSeverity::Critical,
            );

            throw new SigningException('Document signing failed.');
        }

        /** @var array{pdf_b64: string} $decoded */
        return $decoded;
    }
}
