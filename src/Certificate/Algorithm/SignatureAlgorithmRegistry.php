<?php

declare(strict_types=1);

namespace App\Certificate\Algorithm;

use App\Core\Exception\DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Looks up signature suites by their stable id. The *active* suite - the one
 * new keys are generated with - is a deployment setting
 * (SIGIL_SIGNATURE_ACTIVE_ALGORITHM), never a user choice (ADR-006). Reads
 * route by the id stamped on each Certificate, so switching the active suite
 * only redirects new issuance - the same shape as StorageBackendRegistry.
 */
final class SignatureAlgorithmRegistry
{
    /** @var array<string, SignatureAlgorithmInterface> */
    private array $algorithms = [];

    /**
     * @param iterable<SignatureAlgorithmInterface> $algorithms
     */
    public function __construct(
        #[AutowireIterator('app.signature_algorithm')]
        iterable $algorithms,
        #[Autowire('%env(SIGIL_SIGNATURE_ACTIVE_ALGORITHM)%')]
        private readonly string $activeId,
    ) {
        foreach ($algorithms as $algorithm) {
            $this->algorithms[$algorithm->id()] = $algorithm;
        }

        // Fail closed at boot, not at the first issuance.
        $this->get($this->activeId);
    }

    public function get(string $id): SignatureAlgorithmInterface
    {
        return $this->algorithms[$id]
            ?? throw new DomainException(sprintf('Unknown signature algorithm "%s".', $id));
    }

    /** The suite new keys are generated with. */
    public function active(): SignatureAlgorithmInterface
    {
        return $this->get($this->activeId);
    }

    /**
     * @return list<SignatureAlgorithmInterface>
     */
    public function all(): array
    {
        return array_values($this->algorithms);
    }
}
