<?php

declare(strict_types=1);

namespace App\Tests\Functional\Document;

use App\Document\Service\ContentHasher;
use App\Document\Service\Fingerprint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The content fingerprint is keyed, self-describing and verifiable by Sigil -
 * and by nobody without the root key (ADR-004, ADR-012 §7).
 */
final class ContentHasherTest extends KernelTestCase
{
    public function testAFingerprintNamesItsAlgorithmAndVerifiesTheExactBytes(): void
    {
        self::bootKernel();
        $hasher = static::getContainer()->get(ContentHasher::class);

        $stored = $hasher->hash('%PDF-1.4 these bytes');
        $fingerprint = Fingerprint::fromString($stored);

        self::assertSame(ContentHasher::ALGORITHM, $fingerprint->algorithm);
        self::assertMatchesRegularExpression('/^[0-9a-f]{96}$/', $fingerprint->digest);
        self::assertSame($stored, $fingerprint->toString());
        self::assertTrue($hasher->verify('%PDF-1.4 these bytes', $stored));
        self::assertFalse($hasher->verify('%PDF-1.4 these byteS', $stored));
        // Not a plain hash: a checksum tool cannot reproduce it.
        self::assertNotSame(hash('sha384', '%PDF-1.4 these bytes'), $fingerprint->digest);
    }

    public function testALegacyBareDigestStillVerifiesAndAnUnknownAlgorithmNeverDoes(): void
    {
        self::bootKernel();
        $hasher = static::getContainer()->get(ContentHasher::class);

        $legacy = Fingerprint::fromString($hasher->hash('old row'))->digest; // what pre-2026-09-21 rows hold
        self::assertTrue($hasher->verify('old row', $legacy));

        self::assertFalse($hasher->verify('old row', 'HMAC-SHA3-512/v2:'.$legacy), 'an id this build does not know is not trusted');
    }
}
