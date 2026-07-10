<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Exception\DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Local-filesystem storage backend (id "local"). Stores ciphertext blobs under
 * a configured base directory, fanned out by the first two hex chars of the
 * object key to avoid huge flat directories.
 *
 * Object keys are application-generated, but this backend still validates them
 * and resolves paths defensively so a malformed or hostile key can never escape
 * the base directory (path traversal). Primary use is the test environment and
 * non-Docker local runs.
 */
final class LocalStorageBackend implements StorageBackendInterface
{
    /** Keys are hex fan-out dir + '/' + hex name, e.g. "ab/abcdef0123...". */
    private const KEY_PATTERN = '/^[0-9a-f]{2}\/[0-9a-f]{16,}$/';

    private readonly string $baseDir;
    private readonly Filesystem $fs;

    public function __construct(
        #[Autowire('%env(default:default_document_storage_path:SIGIL_DOCUMENT_STORAGE_PATH)%')]
        string $baseDir,
        ?Filesystem $fs = null,
    ) {
        $this->baseDir = rtrim($baseDir, '/');
        $this->fs = $fs ?? new Filesystem();
    }

    public function id(): string
    {
        return 'local';
    }

    public function put(string $objectKey, string $ciphertext): void
    {
        $path = $this->pathFor($objectKey);
        $this->fs->mkdir(\dirname($path));
        // Atomic within the same filesystem: write to a temp file then rename.
        $this->fs->dumpFile($path, $ciphertext);
    }

    public function get(string $objectKey): string
    {
        $path = $this->pathFor($objectKey);
        if (!is_file($path)) {
            throw new DomainException('Stored document not found.');
        }

        $bytes = file_get_contents($path);
        if (false === $bytes) {
            throw new DomainException('Stored document could not be read.');
        }

        return $bytes;
    }

    public function remove(string $objectKey): void
    {
        $this->fs->remove($this->pathFor($objectKey));
    }

    public function has(string $objectKey): bool
    {
        return is_file($this->pathFor($objectKey));
    }

    /**
     * Resolve a validated key to an absolute path and assert it stays inside
     * the base directory.
     */
    private function pathFor(string $objectKey): string
    {
        if (1 !== preg_match(self::KEY_PATTERN, $objectKey)) {
            throw new DomainException('Invalid storage key.');
        }

        $path = $this->baseDir.'/'.$objectKey;

        // Defence in depth: even a pattern-passing key must not resolve outside base.
        if (!str_starts_with($path, $this->baseDir.'/') || str_contains($path, '/../')) {
            throw new DomainException('Invalid storage key.');
        }

        return $path;
    }
}
