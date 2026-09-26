<?php

declare(strict_types=1);

namespace App\Tests\Unit\Signing;

use App\Signing\Service\DigiCertTsaProvider;
use App\Signing\Service\FreeTsaProvider;
use App\Signing\Service\NoTsaProvider;
use App\Signing\Service\TsaProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TsaProviderRegistryTest extends KernelTestCase
{
    public function testTheActiveIdPicksTheProvider(): void
    {
        $providers = [new DigiCertTsaProvider('http://digicert.test'), new FreeTsaProvider('https://freetsa.test'), new NoTsaProvider()];

        self::assertSame('http://digicert.test', (new TsaProviderRegistry($providers, 'digicert'))->activeUrl());
        self::assertSame('https://freetsa.test', (new TsaProviderRegistry($providers, 'freetsa'))->activeUrl());
        self::assertNull((new TsaProviderRegistry($providers, 'none'))->activeUrl(), 'none means PAdES-B-B');
    }

    public function testDigiCertNeedsNoUrlInTheEnvironment(): void
    {
        // A server .env written before DigiCert existed only sets the active id.
        $provider = self::getContainer()->get(DigiCertTsaProvider::class);
        self::assertInstanceOf(DigiCertTsaProvider::class, $provider);

        self::assertSame('http://timestamp.digicert.com', $provider->url());
    }
}
