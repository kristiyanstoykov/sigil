<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificate;

use App\Certificate\Service\PinHasher;
use PHPUnit\Framework\TestCase;

/** The one PIN hashing seam (ADR-008): explicit Argon2id costs, rehash on cost change. */
final class PinHasherTest extends TestCase
{
    public function testHashesVerifyAndCarryTheConfiguredCosts(): void
    {
        $hasher = new PinHasher(memoryCost: 8192, timeCost: 2, threads: 1);
        $hash = $hasher->hash('135790');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringContainsString('m=8192,t=2,p=1', $hash);
        self::assertTrue($hasher->verify('135790', $hash));
        self::assertFalse($hasher->verify('135791', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testAHashMadeWithOtherCostsIsFlaggedForRehashButStillVerifies(): void
    {
        $old = (new PinHasher(memoryCost: 8192, timeCost: 2, threads: 1))->hash('135790');
        $current = new PinHasher(memoryCost: 16384, timeCost: 3, threads: 1);

        // A cost change never locks anyone out; it upgrades on the next good PIN.
        self::assertTrue($current->verify('135790', $old));
        self::assertTrue($current->needsRehash($old));
        self::assertFalse($current->needsRehash($current->hash('135790')));
    }
}
