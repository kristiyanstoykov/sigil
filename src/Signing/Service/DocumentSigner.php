<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Entity\Certificate;
use App\Certificate\Service\PinGate;
use App\Certificate\Service\SuiteCredentials;
use App\Core\Doctrine\RowLock;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentVersion;
use App\Document\Enum\DocumentVersionKind;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentVersionWriter;
use App\Signing\Entity\SigningRequest;
use App\Signing\Event\DocumentSigned;
use App\Signing\Exception\TokenPinRejectedException;
use App\Signing\Repository\SigningRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

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
        private readonly SigningRequestRepository $requests,
        private readonly SigningRequestService $requestService,
        private readonly EventDispatcherInterface $events,
        private readonly EntityManagerInterface $em,
        private readonly SignatureAlgorithmRegistry $algorithms,
        private readonly SuiteCredentials $credentials,
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
        if (!$certificate->getUser()->is($actor)) {
            throw new DomainException('You can only sign with your own certificate.');
        }

        $signingRequest = $this->requests->findPendingForDocument($document);
        $this->assertMaySign($document, $signingRequest, $actor);

        $latest = $document->getLatestVersion();
        if (null === $latest) {
            throw new DomainException('This document has no content to sign.');
        }

        // The chain embedded in the signature is the one that issued this
        // certificate: its suite's CA (ADR-014), not whichever suite is active.
        $caCertPath = $this->credentials->caCertPath($this->algorithms->get($certificate->getAlgorithmId()));
        if (!is_file($caCertPath)) {
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
            caChainPem: (string) file_get_contents($caCertPath),
            signerName: mb_strtoupper($actor->getFullName()),
            algorithmId: $certificate->getAlgorithmId(),
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

        // The signed version and the turn moving on commit together. The token
        // step above is done and cannot be undone; the stored ciphertext cannot
        // join the transaction, so a rollback takes it back by hand.
        $version = null;
        try {
            $this->em->wrapInTransaction(function () use ($document, $actor, $signedPdf, $certificate, $signingRequest, &$version): void {
                // The token has signed, but the world may have moved while it did:
                // a delivery or a withdrawal that committed meanwhile. Re-read the
                // document under lock and ask "may sign" again before minting.
                RowLock::acquire($this->em, $document);
                $this->assertMaySign($document, $this->requests->findPendingForDocument($document), $actor);

                $version = $this->versionWriter->write(
                    $document,
                    $actor,
                    $signedPdf,
                    DocumentVersionKind::Signed,
                    'document.signed',
                    ['certificateSerial' => $certificate->getSerialNumber()],
                );

                if (null !== $signingRequest) {
                    $this->requestService->recordSignature($signingRequest, $actor, $version);
                }
            });
        } catch (\Throwable $e) {
            if (null !== $version) {
                $this->versionWriter->discard($version);
            }
            throw $e;
        }
        \assert($version instanceof DocumentVersion);

        $remaining = null !== $signingRequest ? \count($signingRequest->getSigners()) - $signingRequest->signedCount() : 0;
        $this->events->dispatch(new DocumentSigned($document, $actor, $version, $remaining));

        return $version;
    }

    /**
     * Who may sign right now: under a pending request, only whoever holds the
     * turn; otherwise only the owner, and only while the document is unsigned.
     *
     * @throws DomainException when it is not this user's signature to give
     */
    private function assertMaySign(Document $document, ?SigningRequest $request, User $actor): void
    {
        // Terminal for everyone, turn-holder included: what was served must stay
        // the version the delivery receipt attests.
        if ($document->isDelivered()) {
            throw new DomainException('This document has been delivered, so it is final and cannot be signed.');
        }

        if (null !== $request) {
            $this->requestService->assertTurnOpen($request, $actor);

            return;
        }

        if (!$document->getOwner()->is($actor)) {
            throw new DomainException('You can only sign your own document.');
        }

        // Sign-once, for the self-signing path only: a request is explicitly a
        // sequence of signatures on the same document.
        if ($document->isSigned()) {
            throw new DomainException('This document has already been signed.');
        }
    }
}
