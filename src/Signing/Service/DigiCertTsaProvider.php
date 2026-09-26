<?php

declare(strict_types=1);

namespace App\Signing\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * DigiCert's public RFC-3161 responder: answers in well under a second, where
 * FreeTSA swings between one and many, and its chain is on Adobe's trust list.
 * Plain HTTP is normal for RFC 3161 - the token itself is signed.
 */
final class DigiCertTsaProvider implements TsaProviderInterface
{
    public function __construct(
        #[Autowire('%env(SIGIL_TSA_DIGICERT_URL)%')]
        private readonly string $endpoint,
    ) {
    }

    public function id(): string
    {
        return 'digicert';
    }

    public function url(): string
    {
        return $this->endpoint;
    }
}
