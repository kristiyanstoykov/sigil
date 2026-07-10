<?php

declare(strict_types=1);

namespace App\Document\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One concrete object-storage backend (ADR-009): local filesystem, MinIO, or
 * AWS S3. Each is identified by a stable id that gets stamped into every
 * storage key it writes, so the router can send a read back to the backend that
 * actually holds the object. Adding a backend = adding a tagged service.
 *
 * Object keys are application-generated (see {@see StorageKeyGenerator}) and
 * only ever address ciphertext.
 */
#[AutoconfigureTag('app.storage_backend')]
interface StorageBackendInterface
{
    /** Stable id stamped into storage keys, e.g. "local", "minio", "aws". Never reuse. */
    public function id(): string;

    public function put(string $objectKey, string $ciphertext): void;

    /** @throws \App\Core\Exception\DomainException if the object is missing or unreadable */
    public function get(string $objectKey): string;

    public function remove(string $objectKey): void;

    public function has(string $objectKey): bool;
}
