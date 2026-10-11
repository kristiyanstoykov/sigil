<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\DataExport;
use App\Account\Enum\DataExportStatus;
use App\Account\Event\DataExportFinished;
use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentVersion;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentDownloader;
use App\TempDownload\Service\TempDownloadService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds a data export in the worker (ADR-015 §7): the latest version of every
 * document the owner can open, as zip parts of at most
 * `app.data_export.part_bytes`, each published to TempDownload under the export's id.
 *
 * The parts are planned from stored sizes before anything is decrypted, so the
 * file names can say "part 1 of 3". Each zip is assembled in a scratch
 * directory that is a tmpfs in Docker, so plaintext never reaches a disk.
 */
final class DataExportBuilder
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentKeyGrantRepository $grants,
        private readonly DocumentDownloader $documentDownloader,
        private readonly TempDownloadService $tempDownloads,
        private readonly EntityManagerInterface $em,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::SIGIL_EXPORT_SCRATCH_DIR')]
        private readonly ?string $scratchDir,
        #[Autowire('%app.data_export.part_bytes%')]
        private readonly int $partBytes,
    ) {
    }

    public function build(DataExport $export): void
    {
        // A redelivered message must not build twice.
        if (DataExportStatus::Queued !== $export->getStatus()) {
            return;
        }

        $owner = $export->getOwner();
        $export->start();
        $this->em->flush();

        try {
            $entries = $this->collect($owner);
            $parts = $this->plan($entries);
            $date = $this->clock->now()->format('Y-m-d');

            foreach ($parts as $i => $part) {
                $filename = 1 === \count($parts)
                    ? sprintf('sigil-export-%s.zip', $date)
                    : sprintf('sigil-export-%s-part-%d-of-%d.zip', $date, $i + 1, \count($parts));

                $this->tempDownloads->publish(
                    $owner,
                    $filename,
                    'application/zip',
                    $this->zip($export, $owner, $part, $i + 1),
                    $export->getId(),
                );
            }

            $export->finish(\count($entries), \count($parts), $this->clock->now());
            $this->em->flush();

            $this->auditLogger->log(
                action: 'data_export.ready',
                actor: $owner,
                payload: ['files' => \count($entries), 'parts' => \count($parts)],
                subjectType: 'DataExport',
                subjectId: $export->getId()->toRfc4122(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Data export failed.', ['exportId' => $export->getId()->toRfc4122(), 'exception' => $e]);
            $this->tempDownloads->discardGroup($owner, $export->getId());
            $export->fail($this->clock->now());
            $this->em->flush();

            $this->auditLogger->log(
                action: 'data_export.failed',
                actor: $owner,
                payload: ['error' => (new \ReflectionClass($e))->getShortName()],
                subjectType: 'DataExport',
                subjectId: $export->getId()->toRfc4122(),
                severity: AuditSeverity::Warning,
            );
        }

        $this->dispatcher->dispatch(new DataExportFinished($export));
    }

    /**
     * @return list<array{path: string, size: int, load: \Closure(): string, meta: array<string, mixed>}>
     */
    private function collect(User $owner): array
    {
        $entries = [];

        foreach ($this->documents->findVisibleTo($owner) as $document) {
            $version = $this->latestReadable($document, $owner);
            if (null === $version) {
                continue;
            }

            $entries[] = [
                'path' => sprintf(
                    'documents/%s (%s).pdf',
                    self::safeName(self::stem($document->getTitle())),
                    substr($document->getId()->toRfc4122(), 0, 8),
                ),
                'size' => $version->getSizeBytes(),
                'load' => fn (): string => $this->documentDownloader->download($version, $owner),
                'meta' => [
                    'type' => 'document',
                    'documentId' => $document->getId()->toRfc4122(),
                    'title' => $document->getTitle(),
                    'role' => $document->getOwner()->is($owner) ? 'owner' : 'shared',
                    'version' => $version->getVersionNumber(),
                    'kind' => $version->getKind()->value,
                    'createdAt' => $version->getCreatedAt()->format(\DATE_ATOM),
                    'fingerprint' => $version->getContentHash(),
                ],
            ];
        }

        return $entries;
    }

    /**
     * The newest version $owner holds a grant on. Usually the document's latest;
     * for a signer whose access ended with their turn, the last one they had.
     */
    private function latestReadable(Document $document, User $owner): ?DocumentVersion
    {
        $versions = $document->getVersions()->toArray();
        usort($versions, static fn (DocumentVersion $a, DocumentVersion $b): int => $b->getVersionNumber() <=> $a->getVersionNumber());

        foreach ($versions as $version) {
            if (null !== $this->grants->findForVersionAndUser($version, $owner)) {
                return $version;
            }
        }

        return null;
    }

    /**
     * First-fit in order: a part closes when the next file would push it past
     * the limit. An empty export is still one part, holding only the manifest.
     *
     * @param list<array{path: string, size: int, load: \Closure(): string, meta: array<string, mixed>}> $entries
     *
     * @return non-empty-list<list<array{path: string, size: int, load: \Closure(): string, meta: array<string, mixed>}>>
     */
    private function plan(array $entries): array
    {
        $parts = [[]];
        $size = 0;
        foreach ($entries as $entry) {
            $last = \count($parts) - 1;
            if ([] !== $parts[$last] && $size + $entry['size'] > $this->partBytes) {
                $parts[] = [];
                ++$last;
                $size = 0;
            }
            $parts[$last][] = $entry;
            $size += $entry['size'];
        }

        return $parts;
    }

    /**
     * @param list<array{path: string, size: int, load: \Closure(): string, meta: array<string, mixed>}> $entries
     */
    private function zip(DataExport $export, User $owner, array $entries, int $partNumber): string
    {
        $dir = '' !== (string) $this->scratchDir ? (string) $this->scratchDir : sys_get_temp_dir();
        $path = tempnam($dir, 'sigil-export-');
        if (false === $path) {
            throw new \RuntimeException('Could not create a scratch file for the export.');
        }

        try {
            $zip = new \ZipArchive();
            if (true !== $zip->open($path, \ZipArchive::OVERWRITE)) {
                throw new \RuntimeException('Could not open the export archive.');
            }

            $manifest = [];
            foreach ($entries as $entry) {
                $zip->addFromString($entry['path'], ($entry['load'])(), \ZipArchive::FL_ENC_UTF_8);
                $zip->setCompressionName($entry['path'], \ZipArchive::CM_STORE); // PDFs are compressed already
                $manifest[] = ['path' => $entry['path']] + $entry['meta'];
            }

            $zip->addFromString('manifest.json', json_encode([
                'exportId' => $export->getId()->toRfc4122(),
                'account' => $owner->getEmail(),
                'generatedAt' => $this->clock->now()->format(\DATE_ATOM),
                'part' => $partNumber,
                'files' => $manifest,
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            if (!$zip->close()) {
                throw new \RuntimeException('Could not write the export archive.');
            }

            $bytes = file_get_contents($path);
            if (false === $bytes) {
                throw new \RuntimeException('Could not read the export archive back.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    private static function stem(string $title): string
    {
        return preg_replace('/\.pdf$/i', '', $title) ?? $title;
    }

    /** A title as one path segment: no separators, no control or reserved characters. */
    private static function safeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $name) ?? '');
        $name = mb_substr($name, 0, 100);

        return '' === $name || '.' === $name || '..' === $name ? 'document' : $name;
    }
}
