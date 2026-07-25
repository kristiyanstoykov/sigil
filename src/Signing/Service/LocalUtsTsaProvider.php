<?php

declare(strict_types=1);

namespace App\Signing\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The local RFC-3161 responder (the `tsa` compose service, see docker/tsa/).
 * Gives fast, offline PAdES-B-T timestamps in development so signing does not
 * wait on a public TSA. Dev-only - not a trusted authority.
 */
final class LocalUtsTsaProvider implements TsaProviderInterface
{
    public function __construct(
        #[Autowire('%env(SIGIL_TSA_LOCAL_UTS_URL)%')]
        private readonly string $endpoint,
    ) {
    }

    public function id(): string
    {
        return 'local_uts';
    }

    public function url(): string
    {
        return $this->endpoint;
    }
}
