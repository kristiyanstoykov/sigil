<?php

declare(strict_types=1);

namespace App\Receipt\Service;

use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Entity\User;
use App\Document\Service\ContentHasher;
use App\Document\Service\DocumentStorageInterface;
use App\Document\Service\KeyManagementService;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Entity\DeliveryReceiptKeyGrant;
use App\Signing\Enum\SigningRequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Persists a sealed receipt the same way a document version is persisted
 * (ADR-004/006): fresh DEK → AES-256-GCM encrypt → store ciphertext through the
 * active storage backend → one wrapped-DEK grant per participant.
 *
 * The receipt therefore lands in whichever backend SIGIL_STORAGE_ACTIVE_BACKEND
 * points at (MinIO, S3, local), and object storage still only ever holds
 * ciphertext.
 */
final class ReceiptWriter
{
    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly ContentHasher $contentHasher,
        private readonly KeyManagementService $keys,
        private readonly DocumentStorageInterface $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<User> $participants everyone who may read the receipt - the requester and every signer
     */
    public function write(
        Uuid $documentId,
        Uuid $signingRequestId,
        string $documentTitle,
        string $documentHash,
        SigningRequestStatus $outcome,
        string $pdfBytes,
        \DateTimeImmutable $sealedAt,
        string $sealSerialNumber,
        array $participants,
    ): DeliveryReceipt {
        $receipt = new DeliveryReceipt(
            documentId: $documentId,
            signingRequestId: $signingRequestId,
            documentTitle: $documentTitle,
            documentHash: $documentHash,
            outcome: $outcome,
            contentHash: $this->contentHasher->hash($pdfBytes),
            sizeBytes: \strlen($pdfBytes),
            sealedAt: $sealedAt,
            sealSerialNumber: $sealSerialNumber,
        );

        $dek = $this->encryption->generateKey();
        try {
            $envelope = $this->encryption->encrypt($pdfBytes, $dek, $receipt->dekAad());
            $receipt->setStorageKey($this->storage->store($envelope));

            $this->em->persist($receipt);

            foreach (self::dedupe($participants) as $participant) {
                $this->em->persist(new DeliveryReceiptKeyGrant(
                    $receipt,
                    $participant,
                    $this->keys->wrapDek($participant, $dek, $receipt->dekAad()),
                ));
            }

            $this->em->flush();
        } finally {
            sodium_memzero($dek);
        }

        return $receipt;
    }

    /**
     * @param list<User> $users
     *
     * @return list<User>
     */
    private static function dedupe(array $users): array
    {
        $unique = [];
        foreach ($users as $user) {
            $unique[$user->getId()->toRfc4122()] = $user;
        }

        return array_values($unique);
    }
}
