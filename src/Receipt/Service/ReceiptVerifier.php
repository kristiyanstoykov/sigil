<?php

declare(strict_types=1);

namespace App\Receipt\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Exception\NoAccessException;
use App\Document\Service\ContentHasher;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Repository\DeliveryReceiptKeyGrantRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Answers "is this file the document the receipt is about?" (ADR-012 §7). The
 * fingerprint on a receipt is a keyed MAC only Sigil can recompute, so this is
 * the one place a holder can act on it.
 *
 * Only a grant holder may ask, because a yes/no over arbitrary files is a
 * confirmation oracle to anyone else. The answer is audited against the
 * RECEIPT, not the document: which files a recipient holds is their business,
 * and a row on the document would reach the sender's audit page - the
 * retrieval tracking ADR-012 rules out.
 */
final class ReceiptVerifier
{
    public function __construct(
        private readonly DeliveryReceiptKeyGrantRepository $grants,
        private readonly ContentHasher $hasher,
        private readonly AuditLoggerInterface $auditLogger,
        #[Autowire(param: 'app.max_document_size_bytes')]
        private readonly int $maxSizeBytes,
    ) {
    }

    /**
     * @throws NoAccessException if the user holds no grant on the receipt
     * @throws DomainException   if there is nothing to compare, or the file is empty or oversized
     */
    public function verify(DeliveryReceipt $receipt, User $user, string $bytes): bool
    {
        if (null === $this->grants->findForReceiptAndUser($receipt, $user)) {
            throw new NoAccessException('You do not have access to this receipt.');
        }
        if ('' === $receipt->getDocumentHash()) {
            throw new DomainException('This receipt names no document version, so there is nothing to compare against.');
        }
        if ('' === $bytes) {
            throw new DomainException('The uploaded file is empty.');
        }
        if (\strlen($bytes) > $this->maxSizeBytes) {
            throw new DomainException(sprintf('The file exceeds the %d MB limit.', intdiv($this->maxSizeBytes, 1024 * 1024)));
        }

        $matches = $this->hasher->verify($bytes, $receipt->getDocumentHash());

        $this->auditLogger->log(
            action: 'receipt.verified',
            actor: $user,
            payload: [
                'receiptId' => $receipt->getId()->toRfc4122(),
                'matches' => $matches,
                'sizeBytes' => \strlen($bytes),
            ],
            subjectType: 'DeliveryReceipt',
            subjectId: $receipt->getId()->toRfc4122(),
        );

        return $matches;
    }
}
