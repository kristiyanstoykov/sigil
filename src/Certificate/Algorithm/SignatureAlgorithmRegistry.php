<?php

declare(strict_types=1);

namespace App\Certificate\Algorithm;

use App\Core\Exception\DomainException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Looks up signature suites by their stable id. The default is fixed here
 * (ADR-006) - it is a deployment decision, not a user choice.
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
    ) {
        foreach ($algorithms as $algorithm) {
            $this->algorithms[$algorithm->id()] = $algorithm;
        }
    }

    public function get(string $id): SignatureAlgorithmInterface
    {
        return $this->algorithms[$id]
            ?? throw new DomainException(sprintf('Unknown signature algorithm "%s".', $id));
    }

    public function default(): SignatureAlgorithmInterface
    {
        return $this->get(EcdsaP384Sha384::ID);
    }

    /**
     * @return list<SignatureAlgorithmInterface>
     */
    public function all(): array
    {
        return array_values($this->algorithms);
    }
}
