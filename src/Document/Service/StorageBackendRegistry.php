<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Exception\DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Looks up storage backends by their stable id and knows which one is currently
 * *active* (ADR-009). The active backend - a deployment setting
 * (`SIGIL_STORAGE_ACTIVE_BACKEND`), not a user choice - receives all new
 * writes. Any registered backend can still serve reads for objects it holds.
 * This mirrors the cipher registry's default()/get() split for crypto agility.
 */
final class StorageBackendRegistry
{
    /** @var array<string, StorageBackendInterface> */
    private array $backends = [];

    /**
     * @param iterable<StorageBackendInterface> $backends
     */
    public function __construct(
        #[AutowireIterator('app.storage_backend')]
        iterable $backends,
        #[Autowire('%env(SIGIL_STORAGE_ACTIVE_BACKEND)%')]
        private readonly string $activeId,
    ) {
        foreach ($backends as $backend) {
            $this->backends[$backend->id()] = $backend;
        }
    }

    public function get(string $id): StorageBackendInterface
    {
        return $this->backends[$id]
            ?? throw new DomainException(sprintf('Unknown storage backend "%s".', $id));
    }

    /** The backend that receives all new writes. */
    public function active(): StorageBackendInterface
    {
        return $this->get($this->activeId);
    }

    /**
     * @return list<StorageBackendInterface>
     */
    public function all(): array
    {
        return array_values($this->backends);
    }
}
