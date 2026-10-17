<?php

declare(strict_types=1);

namespace App\TempDownload\Service;

use App\AuditLog\AuditLoggerInterface;
use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Entity\User;
use App\Document\Exception\NoAccessException;
use App\Document\Service\DocumentStorageInterface;
use App\Document\Service\KeyManagementService;
use App\TempDownload\Entity\TempDownload;
use App\TempDownload\Repository\TempDownloadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Hands a file to its owner once, within 24 hours (ADR-015 §8). Knows nothing
 * about what it carries: producers publish bytes, the owner takes them once.
 */
final class TempDownloadService
{
    public const string TTL = '+24 hours';

    public function __construct(
        private readonly EncryptionServiceInterface $encryption,
        private readonly KeyManagementService $keys,
        private readonly DocumentStorageInterface $storage,
        private readonly TempDownloadRepository $downloads,
        private readonly EntityManagerInterface $em,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(
        User $owner,
        string $filename,
        string $mimeType,
        string $bytes,
        ?Uuid $groupId = null,
    ): TempDownload {
        $download = new TempDownload(
            $owner,
            $filename,
            $mimeType,
            \strlen($bytes),
            $this->clock->now()->modify(self::TTL),
            $groupId,
        );

        $dek = $this->encryption->generateKey();
        try {
            $storageKey = $this->storage->store($this->encryption->encrypt($bytes, $dek, $download->dekAad()));
            $download->stored($storageKey, $this->keys->wrapDek($owner, $dek, $download->dekAad()));
        } finally {
            sodium_memzero($dek);
        }

        try {
            $this->em->persist($download);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->deleteCiphertext($storageKey);

            throw $e;
        }

        return $download;
    }

    /**
     * Return the plaintext and spend the one download. The claim comes after the
     * bytes are in hand, so a slow or failed fetch leaves the download available;
     * of two concurrent takes, both may decrypt but only the one that claims
     * gets the bytes. The ciphertext is deleted once claimed.
     *
     * @throws NoAccessException when it is not $user's, already taken or expired
     */
    public function take(TempDownload $download, User $user): string
    {
        if (!$download->getOwner()->is($user) || !$download->isAvailable($this->clock->now())) {
            throw new NoAccessException('This download is not available.');
        }

        $dek = $this->keys->unwrapDek($user, $download->getWrappedDek(), $download->dekAad());
        try {
            $bytes = $this->encryption->decrypt(
                $this->storage->retrieve($download->getStorageKey()),
                $dek,
                $download->dekAad(),
            );
        } finally {
            sodium_memzero($dek);
        }

        if (!$this->downloads->claim($download, $this->clock->now())) {
            throw new NoAccessException('This download is not available.');
        }

        $this->deleteCiphertext($download->getStorageKey());

        $this->auditLogger->log(
            action: 'temp_download.downloaded',
            actor: $user,
            payload: ['filename' => $download->getFilename(), 'sizeBytes' => $download->getSizeBytes()],
            subjectType: 'TempDownload',
            subjectId: $download->getId()->toRfc4122(),
        );

        return $bytes;
    }

    /** Remove a group that never became complete - a producer that failed half way. */
    public function discardGroup(User $owner, Uuid $groupId): void
    {
        foreach ($this->downloads->findGroup($owner, $groupId) as $download) {
            $this->remove($download);
        }
        $this->em->flush();
    }

    /**
     * Delete every spent or expired download, ciphertext first.
     *
     * @return int how many rows went
     */
    public function sweep(): int
    {
        $swept = 0;
        foreach ($this->downloads->findSweepable($this->clock->now()) as $download) {
            if ($this->remove($download)) {
                ++$swept;
            }
        }
        $this->em->flush();

        return $swept;
    }

    /** The row only goes once its ciphertext has, so a failed delete is retried by the next sweep. */
    private function remove(TempDownload $download): bool
    {
        if (!$this->deleteCiphertext($download->getStorageKey())) {
            return false;
        }
        $this->em->remove($download);

        return true;
    }

    /** Idempotent: an object that is already gone counts as deleted. */
    private function deleteCiphertext(string $storageKey): bool
    {
        if ('' === $storageKey) {
            return true;
        }

        try {
            if ($this->storage->exists($storageKey)) {
                $this->storage->delete($storageKey);
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Could not delete a temporary download.', ['storageKey' => $storageKey, 'exception' => $e]);

            return false;
        }
    }
}
