<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\Certificate\Entity\Certificate;
use App\Certificate\Service\PinGate;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentVersion;
use App\Document\Enum\DocumentVersionKind;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentVersionWriter;
use App\Signing\Exception\TokenPinRejectedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signs a document's latest version and stores the result as a new
 * {@see DocumentVersion} (ADR-007). Fully synchronous: the PIN is present only
 * for this call - it opens the PKCS#11 session and is then gone.
 *
 * Order (ADR-008 hash-first): PinGate (DB gate) → decrypt latest version →
 * PAdES sign in the token → re-encrypt the signed bytes as a fresh Signed
 * version with its own DEK + owner grant → audit. A token PIN rejection after
 * the hash gate passed trips the desync alarm and locks the certificate.
 */
final class DocumentSigner
{
    public function __construct(
        private readonly PinGate $pinGate,
        private readonly DocumentDownloader $downloader,
        private readonly PadesSignerInterface $signer,
        private readonly TsaProviderRegistry $tsa,
        private readonly DocumentVersionWriter $versionWriter,
        #[Autowire('%kernel.project_dir%/var/ca/ca.crt')]
        private readonly string $caCertPath,
    ) {
    }

    /**
     * @param User $actor the signer - must own both the document and the certificate
     *
     * @throws DomainException            on a wrong PIN, a locked/unusable certificate, or a signing failure
     * @throws TokenPinRejectedException  when the token rejects a hash-accepted PIN (desync; cert is locked)
     */
    public function sign(Document $document, Certificate $certificate, User $actor, #[\SensitiveParameter] string $pin): DocumentVersion
    {
        if ($document->getOwner() !== $actor || $certificate->getUser() !== $actor) {
            throw new DomainException('You can only sign your own document with your own certificate.');
        }

        // Sign-once. The controller blocks this earlier; this is the layer that
        // makes it true for every caller, including future non-web ones.
        if ($document->isSigned()) {
            throw new DomainException('This document has already been signed.');
        }

        $latest = $document->getLatestVersion();
        if (null === $latest) {
            throw new DomainException('This document has no content to sign.');
        }

        if (!is_file($this->caCertPath)) {
            throw new DomainException('The certificate authority is not initialized (run sigil:ca:init).');
        }

        // ADR-008 gate: verify against the Argon2id hash before the token ever
        // sees the PIN. Throws (and audits) on a wrong PIN or locked cert.
        $this->pinGate->verify($certificate, $pin);

        $pdfBytes = $this->downloader->download($latest, $actor);

        $request = new PadesSignRequest(
            pdfBytes: $pdfBytes,
            tokenLabel: $certificate->getTokenLabel(),
            keyLabel: $certificate->getKeyLabel(),
            signingCertPem: $certificate->getCertificatePem(),
            caChainPem: (string) file_get_contents($this->caCertPath),
            signerName: mb_strtoupper($actor->getFullName()),
            tsaUrl: $this->tsa->activeUrl(),
            // A Sigil-namespaced, unique field name. It must not collide with a
            // field already in the PDF - externally-signed documents (Borica,
            // Evrotrust, …) already carry "Signature1", "Signature2", … so we
            // never reuse that scheme. Random suffix = collision-proof.
            fieldName: sprintf('SigilSignature-v%d-%s', $document->nextVersionNumber(), bin2hex(random_bytes(4))),
        );

        try {
            $signedPdf = $this->signer->sign($request, $pin);
        } catch (TokenPinRejectedException $e) {
            // Hash matched but the token said no: hash⇄token desync. Lock the
            // certificate and re-raise - re-issue is the only recovery.
            $this->pinGate->reportTokenPinRejected($certificate);

            throw $e;
        }

        return $this->versionWriter->write(
            $document,
            $actor,
            $signedPdf,
            DocumentVersionKind::Signed,
            'document.signed',
            ['certificateSerial' => $certificate->getSerialNumber()],
        );
    }
}
