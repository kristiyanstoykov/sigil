<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\AccountClosureRecord;
use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Entity\User;
use App\Document\Exception\NoAccessException;
use App\Document\Service\ContentHasher;
use App\Document\Service\DocumentStorageInterface;
use App\Document\Service\KeyManagementService;
use App\Receipt\Service\ReceiptRendererInterface;
use App\Receipt\Service\ReceiptSealer;
use Psr\Log\LoggerInterface;

/**
 * Renders, seals and stores the account closure record (ADR-015 §3). It runs
 * while the user's KEK is still live, before the deletion transaction archives
 * it; the caller persists the row inside that transaction, or discards it.
 */
final class ClosureRecordSealer
{
    private const string TEMPLATE = 'account/closure_record.pdf.html.twig';

    public function __construct(
        private readonly ReceiptRendererInterface $renderer,
        private readonly ReceiptSealer $sealer,
        private readonly EncryptionServiceInterface $encryption,
        private readonly ContentHasher $contentHasher,
        private readonly KeyManagementService $keys,
        private readonly DocumentStorageInterface $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{record: AccountClosureRecord, pdf: string} the row is not persisted yet
     */
    public function seal(User $user, string $formerName, string $formerEmail, AccountDeletionSummary $summary): array
    {
        $pdf = $this->renderer->render(self::TEMPLATE, [
            'summary' => $summary,
            'name' => $formerName,
            'email' => $formerEmail,
            'sealedAt' => $summary->deletedAt,
        ]);
        $sealed = $this->sealer->sealWith($pdf, 'Account closure record', 'Electronic seal (account closure)');
        $bytes = $sealed['bytes'];

        $record = new AccountClosureRecord(
            $user,
            $this->contentHasher->hash($bytes),
            \strlen($bytes),
            $summary->deletedAt,
            $sealed['serialNumber'],
        );

        $dek = $this->encryption->generateKey();
        try {
            $storageKey = $this->storage->store($this->encryption->encrypt($bytes, $dek, $record->dekAad()));
            $record->stored($storageKey, $this->keys->wrapDek($user, $dek, $record->dekAad()));
        } finally {
            sodium_memzero($dek);
        }

        return ['record' => $record, 'pdf' => $bytes];
    }

    /** @throws NoAccessException when $user is not the record's owner */
    public function read(AccountClosureRecord $record, User $user): string
    {
        if (!$record->getUser()->is($user)) {
            throw new NoAccessException('This record is not yours.');
        }

        $dek = $this->keys->unwrapDek($user, $record->getWrappedDek(), $record->dekAad());
        try {
            return $this->encryption->decrypt($this->storage->retrieve($record->getStorageKey()), $dek, $record->dekAad());
        } finally {
            sodium_memzero($dek);
        }
    }

    /** Deletes the ciphertext; idempotent. True once it is gone. */
    public function discard(AccountClosureRecord $record): bool
    {
        if ('' === $record->getStorageKey()) {
            return true;
        }

        try {
            if ($this->storage->exists($record->getStorageKey())) {
                $this->storage->delete($record->getStorageKey());
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Could not delete a closure record\'s ciphertext.', ['storageKey' => $record->getStorageKey(), 'exception' => $e]);

            return false;
        }
    }
}
