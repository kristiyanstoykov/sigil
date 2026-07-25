<?php

declare(strict_types=1);

namespace App\Signing\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * FreeTSA.org - a public RFC-3161 responder used for the MVP (PAdES-B-T).
 * The endpoint is configurable so it can point at a self-hosted responder
 * without a code change.
 */
final class FreeTsaProvider implements TsaProviderInterface
{
    public function __construct(
        #[Autowire('%env(SIGIL_TSA_FREETSA_URL)%')]
        private readonly string $endpoint,
    ) {
    }

    public function id(): string
    {
        return 'freetsa';
    }

    public function url(): string
    {
        return $this->endpoint;
    }
}
