<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificate;

use App\Certificate\Service\PinHasher;
use App\Core\Crypto\AesGcmSodiumCipher;
use App\Core\Crypto\CipherAlgorithmRegistry;
use App\Core\Crypto\EnvelopeEncryptionService;
use App\Core\Crypto\RootKeyProvider;
use PHPUnit\Framework\TestCase;

/** The one PIN hashing seam (ADR-008): peppered under the root key, explicit Argon2id costs, rehash on change. */
final class PinHasherTest extends TestCase
{
    public function testHashesArePepperedVerifyAndCarryTheConfiguredCosts(): void
    {
        $hasher = $this->hasher(memoryCost: 8192, timeCost: 2);
        $hash = $hasher->hash('135790');

        self::assertStringStartsWith(PinHasher::PREFIX.'$argon2id$', $hash);
        self::assertStringContainsString('m=8192,t=2,p=1', $hash);
        self::assertTrue($hasher->verify('135790', $hash));
        self::assertFalse($hasher->verify('135791', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testTheTableAloneConfirmsNothingWithoutTheHostKey(): void
    {
        $hash = $this->hasher(memoryCost: 8192, timeCost: 2)->hash('135790');
        $otherHost = $this->hasher(memoryCost: 8192, timeCost: 2, rootKey: str_repeat('B', 32));

        // Same PIN, same costs, different pepper: the stolen hash is useless to a guesser.
        self::assertFalse($otherHost->verify('135790', $hash));
        self::assertFalse(password_verify('135790', substr($hash, \strlen(PinHasher::PREFIX))), 'nor does raw Argon2id over the PIN match');
    }

    public function testALegacyUnpepperedHashStillVerifiesAndIsFlaggedForRehash(): void
    {
        $legacy = password_hash('135790', \PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 2, 'threads' => 1]);
        $hasher = $this->hasher(memoryCost: 8192, timeCost: 2);

        self::assertTrue($hasher->verify('135790', $legacy));
        self::assertFalse($hasher->verify('135791', $legacy));
        self::assertTrue($hasher->needsRehash($legacy), 'upgraded on the next good PIN');
    }

    public function testAHashMadeWithOtherCostsIsFlaggedForRehashButStillVerifies(): void
    {
        $old = $this->hasher(memoryCost: 8192, timeCost: 2)->hash('135790');
        $current = $this->hasher(memoryCost: 16384, timeCost: 3);

        self::assertTrue($current->verify('135790', $old));
        self::assertTrue($current->needsRehash($old));
        self::assertFalse($current->needsRehash($current->hash('135790')));
    }

    private function hasher(int $memoryCost, int $timeCost, string $rootKey = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'): PinHasher
    {
        return new PinHasher(
            new EnvelopeEncryptionService(new CipherAlgorithmRegistry([new AesGcmSodiumCipher()])),
            new RootKeyProvider(base64_encode($rootKey)),
            memoryCost: $memoryCost,
            timeCost: $timeCost,
            threads: 1,
        );
    }
}
