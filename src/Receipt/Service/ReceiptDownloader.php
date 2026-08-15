<?php

declare(strict_types=1);

namespace App\Receipt\Service;

use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Service\DocumentStorageInterface;
use App\Document\Service\KeyManagementService;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Repository\DeliveryReceiptKeyGrantRepository;

/**
 * Decrypts a sealed receipt for a participant. Mirrors
 * {@see \App\Document\Service\DocumentDownloader}: no grant, no plaintext.
 */
final class ReceiptDownloader
{
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly KeyManagementService $keys,
        private readonly DocumentStorageInterface $storage,
        private readonly DeliveryReceiptKeyGrantRepository $grants,
    ) {
    }

    /**
     * @return string decrypted PDF bytes
     *
     * @throws DomainException if the user holds no grant, or on a crypto/storage failure
     */
    public function download(DeliveryReceipt $receipt, User $user): string
    {
        $grant = $this->grants->findForReceiptAndUser($receipt, $user)
            ?? throw new DomainException('You do not have access to this receipt.');

        $dek = $this->keys->unwrapDek($user, $grant->getWrappedDek(), $receipt->dekAad());
        try {
            $ciphertext = $this->storage->retrieve($receipt->getStorageKey());

            return $this->encryption->decrypt($ciphertext, $dek, $receipt->dekAad());
        } finally {
            sodium_memzero($dek);
        }
    }
}
