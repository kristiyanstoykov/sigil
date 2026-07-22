<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\Core\Exception\DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves the *active* TSA (ADR-009 style, mirroring
 * {@see \App\Document\Service\StorageBackendRegistry}). The active provider - a
 * deployment setting (`SIGIL_TSA_ACTIVE_BACKEND`), not a user choice - decides
 * the URL every new signature is timestamped by.
 */
final class TsaProviderRegistry
{
    /** @var array<string, TsaProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<TsaProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.tsa_provider')]
        iterable $providers,
        #[Autowire('%env(SIGIL_TSA_ACTIVE_BACKEND)%')]
        private readonly string $activeId,
    ) {
        foreach ($providers as $provider) {
            $this->providers[$provider->id()] = $provider;
        }
    }

    public function active(): TsaProviderInterface
    {
        return $this->providers[$this->activeId]
            ?? throw new DomainException(sprintf('Unknown TSA provider "%s".', $this->activeId));
    }

    /** The active provider's endpoint, or null for no timestamp (PAdES-B-B). */
    public function activeUrl(): ?string
    {
        return $this->active()->url();
    }
}
