<?php

declare(strict_types=1);

namespace App\Receipt\Service;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\DeliveryRecipient;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Enum\ReceiptOutcome;
use App\Receipt\Enum\ReceiptSource;
use App\Receipt\Repository\DeliveryReceiptRepository;
use App\Signing\Entity\SigningRequest;
use App\Signing\Entity\SigningRequestSigner;
use Psr\Clock\ClockInterface;

/**
 * Produces the delivery receipt for a closed signature request: gather the
 * evidence, render, seal, encrypt, grant (ADR-012).
 *
 * Scope note - Sigil attests delivery, not reading. A signer is "delivered to"
 * when the request granted them the key, which is ETSI EN 319 522's consignment
 * event: the act of making the content available to the recipient. Whether they
 * then opened it is deliberately not tracked.
 */
final class ReceiptGenerator
{
    private const string TEMPLATE = 'receipt/delivery_receipt.html.twig';

    public function __construct(
        private readonly ReceiptRendererInterface $renderer,
        private readonly ReceiptSealer $sealer,
        private readonly ReceiptWriter $writer,
        private readonly DeliveryReceiptRepository $receipts,
        private readonly AuditLogEntryRepository $auditEntries,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @throws DomainException if the request is still open or the seal is missing
     */
    public function generateFor(SigningRequest $request): DeliveryReceipt
    {
        if ($request->isPending()) {
            throw new DomainException('A receipt is only issued once the request is closed.');
        }

        $existing = $this->receipts->findForSource(ReceiptSource::SigningRequest, $request->getId());
        if (null !== $existing) {
            return $existing;
        }

        $document = $request->getDocument();
        $documentId = $document->getId();
        $sealedAt = \DateTimeImmutable::createFromInterface($this->clock->now());

        // The version that was delivered: the latest one at close. For a request
        // nobody signed this is still the original upload.
        $latest = $document->getLatestVersion();
        $documentHash = null !== $latest ? $latest->getContentHash() : '';

        $pdf = $this->renderer->render(self::TEMPLATE, [
            'source' => ReceiptSource::SigningRequest,
            'outcome' => ReceiptOutcome::fromSigningRequest($request->getStatus()),
            'request' => $request,
            'delivery' => null,
            'document' => $document,
            'documentTitle' => $document->getTitle(),
            'documentHash' => $documentHash,
            'sender' => $request->getRequester(),
            'people' => $this->signerRows($request),
            'evidence' => $this->auditEntries->findForSubject('Document', $documentId->toRfc4122()),
            'sealedAt' => $sealedAt,
        ]);

        $sealed = $this->sealer->seal($pdf, $document->getTitle());

        $receipt = $this->writer->write(
            documentId: $documentId,
            source: ReceiptSource::SigningRequest,
            sourceId: $request->getId(),
            documentTitle: $document->getTitle(),
            documentHash: $documentHash,
            outcome: ReceiptOutcome::fromSigningRequest($request->getStatus()),
            pdfBytes: $sealed['bytes'],
            sealedAt: $sealedAt,
            sealSerialNumber: $sealed['serialNumber'],
            participants: $this->participants($request),
        );

        $this->auditLogger->log(
            action: 'receipt.sealed',
            actor: $request->getRequester(),
            payload: [
                'receiptId' => $receipt->getId()->toRfc4122(),
                'requestId' => $request->getId()->toRfc4122(),
                'outcome' => $request->getStatus()->value,
                'contentHash' => $receipt->getContentHash(),
                'sealSerial' => $receipt->getSealSerialNumber(),
            ],
            subjectType: 'Document',
            subjectId: $documentId->toRfc4122(),
        );

        return $receipt;
    }

    /**
     * The receipt for a delivery. Unlike a request's, there is nothing to wait
     * for: a delivery is finished the moment it is made, so this is sealed
     * straight away and its outcome is always Delivered.
     */
    public function generateForDelivery(Delivery $delivery): DeliveryReceipt
    {
        $existing = $this->receipts->findForSource(ReceiptSource::Delivery, $delivery->getId());
        if (null !== $existing) {
            return $existing;
        }

        $document = $delivery->getDocument();
        $documentId = $document->getId();
        $sealedAt = \DateTimeImmutable::createFromInterface($this->clock->now());

        // The version actually served, not the latest: a later version is not
        // delivered retroactively, and the receipt must name what was handed over.
        $recipients = array_values($delivery->getRecipients()->toArray());
        $documentHash = [] !== $recipients ? $recipients[0]->getVersion()->getContentHash() : '';

        $pdf = $this->renderer->render(self::TEMPLATE, [
            'source' => ReceiptSource::Delivery,
            'outcome' => ReceiptOutcome::Delivered,
            'request' => null,
            'delivery' => $delivery,
            'document' => $document,
            'documentTitle' => $document->getTitle(),
            'documentHash' => $documentHash,
            'sender' => $delivery->getSender(),
            'people' => array_map(
                static fn (DeliveryRecipient $r): array => ['recipient' => $r, 'deliveredAt' => $r->getDeliveredAt()],
                $recipients,
            ),
            'evidence' => $this->auditEntries->findForSubject('Document', $documentId->toRfc4122()),
            'sealedAt' => $sealedAt,
        ]);

        $sealed = $this->sealer->seal($pdf, $document->getTitle());

        $receipt = $this->writer->write(
            documentId: $documentId,
            source: ReceiptSource::Delivery,
            sourceId: $delivery->getId(),
            documentTitle: $document->getTitle(),
            documentHash: $documentHash,
            outcome: ReceiptOutcome::Delivered,
            pdfBytes: $sealed['bytes'],
            sealedAt: $sealedAt,
            sealSerialNumber: $sealed['serialNumber'],
            participants: $delivery->participants(),
        );

        $this->auditLogger->log(
            action: 'receipt.sealed',
            actor: $delivery->getSender(),
            payload: [
                'receiptId' => $receipt->getId()->toRfc4122(),
                'deliveryId' => $delivery->getId()->toRfc4122(),
                'outcome' => ReceiptOutcome::Delivered->value,
                'contentHash' => $receipt->getContentHash(),
                'sealSerial' => $receipt->getSealSerialNumber(),
            ],
            subjectType: 'Document',
            subjectId: $documentId->toRfc4122(),
        );

        return $receipt;
    }

    /**
     * Delivery is the per-turn key grant, so a signer counts as delivered-to once
     * the turn reached them: everyone up to and including the first unsigned one.
     *
     * The grant for the first signer is minted when the request is sent, and for
     * signer k when signer k-1 signs - so those are the delivery timestamps, with
     * no extra bookkeeping.
     *
     * @return list<array{signer: SigningRequestSigner, deliveredAt: ?\DateTimeImmutable}>
     */
    private function signerRows(SigningRequest $request): array
    {
        $rows = [];
        $deliveredAt = $request->getCreatedAt();

        foreach ($request->orderedSigners() as $signer) {
            $rows[] = ['signer' => $signer, 'deliveredAt' => $deliveredAt];
            // The turn stops at the first signer who did not sign: nobody after
            // them was ever handed the key.
            $deliveredAt = $signer->hasSigned() ? $signer->getSignedAt() : null;
        }

        return $rows;
    }

    /**
     * @return list<User>
     */
    private function participants(SigningRequest $request): array
    {
        $participants = [$request->getRequester()];
        foreach ($request->orderedSigners() as $signer) {
            $participants[] = $signer->getUser();
        }

        return $participants;
    }
}
