<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\ArchivedUserKey;
use App\Account\Repository\AccountClosureRecordRepository;
use App\AuditLog\AuditLoggerInterface;
use App\Core\Doctrine\RowLock;
use App\Delivery\Repository\DeliveryRepository;
use App\Document\Entity\DocumentKeyGrant;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentEraser;
use App\Receipt\Service\ReceiptEraser;
use App\Signing\Repository\SigningRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ten years after a deletion, erases what the account left behind (ADR-015 §6):
 * every document it owns, for everyone, with their receipts; its own grants and
 * closure records; and the archived KEK. Nobody is told. The tombstoned row,
 * its fingerprint and its recovery-code hash stay, so the address can still
 * only come back as that (now empty) account.
 *
 * Each document is erased on its own, so a run that stops part-way leaves the
 * archived key in place and the next run carries on.
 */
final class RetentionPurger
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly SigningRequestRepository $requests,
        private readonly DeliveryRepository $deliveries,
        private readonly DocumentEraser $eraser,
        private readonly ReceiptEraser $receiptEraser,
        private readonly AccountClosureRecordRepository $closureRecords,
        private readonly ClosureRecordSealer $closureSealer,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @return array{documents: int, receipts: int} what went */
    public function purge(ArchivedUserKey $key): array
    {
        $user = $key->getUser();
        $documents = 0;
        $receipts = 0;

        foreach ($this->documents->findByOwner($user) as $document) {
            // Through the ORM first: the database cascade would leave them managed over deleted rows.
            foreach ($this->requests->findAllForDocument($document) as $request) {
                $this->em->remove($request);
            }
            foreach ($this->deliveries->findForDocument($document) as $delivery) {
                $this->em->remove($delivery);
            }
            $this->em->flush();

            $receipts += $this->receiptEraser->eraseForDocument($document->getId());
            $this->eraser->erase($document, null, 'retention_expired');
            ++$documents;
        }

        $this->receiptEraser->dropGrantsOf($user);

        $records = $this->closureRecords->findForUser($user);
        $this->em->wrapInTransaction(function () use ($key, $user, $records, $documents, $receipts): void {
            RowLock::acquire($this->em, $user);

            // Grants on other people's documents: wrapped under the KEK that goes with the key row.
            foreach ($this->em->getRepository(DocumentKeyGrant::class)->findBy(['user' => $user]) as $grant) {
                $this->em->remove($grant);
            }
            foreach ($records as $record) {
                $this->em->remove($record);
            }
            $this->em->remove($key);
            $this->em->flush();

            $this->auditLogger->log(
                action: 'retention.purged',
                payload: ['documentsErased' => $documents, 'receiptsErased' => $receipts],
                subjectType: 'User',
                subjectId: $user->getId()->toRfc4122(),
            );
        });

        foreach ($records as $record) {
            $this->closureSealer->discard($record);
        }

        return ['documents' => $documents, 'receipts' => $receipts];
    }
}
