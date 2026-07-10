<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Exception\DomainException;

/**
 * Default {@see DocumentStorageInterface}. Writes go to the active backend and
 * come back stamped with its id ("minio:ab/cd..."); reads/deletes parse the
 * stamp and route to the backend that holds the object (ADR-009). Switching the
 * active backend therefore only redirects *new* writes - existing objects stay
 * readable as long as their backend remains configured.
 */
final class RoutingDocumentStorage implements DocumentStorageInterface
{
    /** Backend ids are lowercase alphanumerics; the separator is a single colon. */
    private const KEY_FORMAT = '/^([a-z0-9]{2,16}):(.+)$/';

    public function __construct(
        private readonly StorageBackendRegistry $backends,
        private readonly StorageKeyGenerator $keys,
    ) {
    }

    public function store(string $ciphertext): string
    {
        $backend = $this->backends->active();
        $objectKey = $this->keys->generate();
        $backend->put($objectKey, $ciphertext);

        return $backend->id().':'.$objectKey;
    }

    public function retrieve(string $storageKey): string
    {
        [$backend, $objectKey] = $this->route($storageKey);

        return $backend->get($objectKey);
    }

    public function delete(string $storageKey): void
    {
        [$backend, $objectKey] = $this->route($storageKey);
        $backend->remove($objectKey);
    }

    public function exists(string $storageKey): bool
    {
        [$backend, $objectKey] = $this->route($storageKey);

        return $backend->has($objectKey);
    }

    /**
     * @return array{0: StorageBackendInterface, 1: string}
     */
    private function route(string $storageKey): array
    {
        if (1 !== preg_match(self::KEY_FORMAT, $storageKey, $m)) {
            throw new DomainException('Malformed storage key.');
        }

        return [$this->backends->get($m[1]), $m[2]];
    }
}
