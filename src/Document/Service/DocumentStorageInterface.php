<?php

declare(strict_types=1);

namespace App\Document\Service;

/**
 * High-level blob store the application depends on. It ONLY ever holds
 * AES-256-GCM ciphertext (ADR-004) - no knowledge of keys or plaintext.
 *
 * Backed by a router (ADR-009) over one or more {@see StorageBackendInterface}
 * backends. On write it delegates to the currently *active* backend and returns
 * a storage key stamped with that backend's id ("minio:ab/cd..."). On read it
 * routes by the stamped id, so objects written to a backend stay retrievable
 * even after the active backend changes - the same agility idea as the
 * versioned crypto envelope.
 *
 * The returned storage key is what a DocumentVersion persists.
 */
interface DocumentStorageInterface
{
    /** Store ciphertext on the active backend; returns the stamped storage key. */
    public function store(string $ciphertext): string;

    /** @throws \App\Core\Exception\DomainException if the key is malformed or the object is missing */
    public function retrieve(string $storageKey): string;

    public function delete(string $storageKey): void;

    public function exists(string $storageKey): bool;
}
