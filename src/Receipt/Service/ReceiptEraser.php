<?php

declare(strict_types=1);

namespace App\Receipt\Service;

use App\Core\Entity\User;
use App\Document\Service\DocumentStorageInterface;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Entity\DeliveryReceiptKeyGrant;
use App\Receipt\Repository\DeliveryReceiptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Removes receipts once nothing may keep them: the retention purge (ADR-015 §6)
 * is the only caller. Rows first, bytes second, as in DocumentEraser.
 */
final class ReceiptEraser
{
    public function __construct(
        private readonly DeliveryReceiptRepository $receipts,
        private readonly DocumentStorageInterface $storage,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return int how many receipts went */
    public function eraseForDocument(Uuid $documentId): int
    {
        $receipts = $this->receipts->findForDocument($documentId);
        $storageKeys = array_map(static fn (DeliveryReceipt $r): string => $r->getStorageKey(), $receipts);

        foreach ($receipts as $receipt) {
            foreach ($this->em->getRepository(DeliveryReceiptKeyGrant::class)->findBy(['receipt' => $receipt]) as $grant) {
                $this->em->remove($grant);
            }
            $this->em->remove($receipt);
        }
        $this->em->flush();

        foreach ($storageKeys as $key) {
            try {
                $this->storage->delete($key);
            } catch (\Throwable $e) {
                $this->logger->error('Could not delete receipt ciphertext {key}: {message}', ['key' => $key, 'message' => $e->getMessage()]);
            }
        }

        return \count($receipts);
    }

    /** Drops every receipt grant $user holds; the receipts stay for the others named on them. */
    public function dropGrantsOf(User $user): int
    {
        $grants = $this->em->getRepository(DeliveryReceiptKeyGrant::class)->findBy(['user' => $user]);
        foreach ($grants as $grant) {
            $this->em->remove($grant);
        }
        $this->em->flush();

        return \count($grants);
    }
}
