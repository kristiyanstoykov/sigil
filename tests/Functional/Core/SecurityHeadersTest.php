<?php

declare(strict_types=1);

namespace App\Tests\Functional\Core;

use App\Tests\Functional\AuthWebTestCase;

/** Every response carries the anti-clickjacking / anti-sniffing headers; the sign page is a PIN form. */
final class SecurityHeadersTest extends AuthWebTestCase
{
    public function testEveryPageCarriesTheSecurityHeaders(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        self::assertResponseHeaderSame('Content-Security-Policy', "frame-ancestors 'self'");
        self::assertResponseHeaderSame('X-Frame-Options', 'SAMEORIGIN');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Referrer-Policy', 'same-origin');
        self::assertFalse($this->client->getResponse()->headers->has('X-Powered-By'));
    }
}
