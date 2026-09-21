<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use App\Core\Crypto\Exception\DecryptionFailedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The root-key wrapper the application depends on (ADR-010): wraps with the
 * *active* scheme and unwraps with whichever scheme a blob's leading byte
 * names. Switching SIGIL_ROOT_WRAPPER_ACTIVE therefore only redirects new
 * wraps - every stored KEK stays readable, exactly as storage keys route to
 * their backend (ADR-009) and envelopes to their cipher (ADR-006).
 * `sigil:root-key:migrate` remains the way to move existing KEKs over.
 */
final class RoutingRootKeyWrapper implements RootKeyWrapperInterface
{
    /** @var array<string, RootKeyWrapperSchemeInterface> keyed by scheme byte */
    private array $bySchemeByte = [];

    /** @var array<string, RootKeyWrapperSchemeInterface> keyed by id */
    private array $byId = [];

    /**
     * @param iterable<RootKeyWrapperSchemeInterface> $wrappers
     */
    public function __construct(
        #[AutowireIterator('app.root_key_wrapper')]
        iterable $wrappers,
        #[Autowire('%env(SIGIL_ROOT_WRAPPER_ACTIVE)%')]
        private readonly string $activeId,
    ) {
        foreach ($wrappers as $wrapper) {
            $this->bySchemeByte[$wrapper->schemeByte()] = $wrapper;
            $this->byId[$wrapper->id()] = $wrapper;
        }

        if (!isset($this->byId[$this->activeId])) {
            throw new \InvalidArgumentException(sprintf('Unknown active root-key wrapper "%s".', $this->activeId));
        }
    }

    public function wrapKek(#[\SensitiveParameter] string $rawKek, string $aad): string
    {
        return $this->byId[$this->activeId]->wrapKek($rawKek, $aad);
    }

    public function unwrapKek(string $wrappedKek, string $aad): string
    {
        // An unknown scheme is a decryption failure like any other: never
        // reveal which byte would have been recognised.
        $wrapper = '' !== $wrappedKek ? ($this->bySchemeByte[$wrappedKek[0]] ?? null) : null;

        return ($wrapper ?? throw new DecryptionFailedException())->unwrapKek($wrappedKek, $aad);
    }
}
