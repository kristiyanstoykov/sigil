<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crypto;

use App\Core\Crypto\AesGcmSodiumCipher;
use App\Core\Crypto\CipherAlgorithmRegistry;
use App\Core\Crypto\EnvelopeEncryptionService;
use App\Core\Crypto\Exception\DecryptionFailedException;
use PHPUnit\Framework\TestCase;

final class EnvelopeEncryptionServiceTest extends TestCase
{
    private EnvelopeEncryptionService $service;

    protected function setUp(): void
    {
        $registry = new CipherAlgorithmRegistry([new AesGcmSodiumCipher()]);
        $this->service = new EnvelopeEncryptionService($registry);
    }

    public function testRoundTrip(): void
    {
        $key = $this->service->generateKey();
        $plaintext = 'the quick brown fox jumps over the lazy dog';

        $envelope = $this->service->encrypt($plaintext, $key);

        self::assertNotSame($plaintext, $envelope);
        self::assertSame($plaintext, $this->service->decrypt($envelope, $key));
    }

    public function testEnvelopeIsSelfDescribing(): void
    {
        $key = $this->service->generateKey();
        $envelope = $this->service->encrypt('x', $key);

        self::assertSame(0x01, \ord($envelope[0]), 'format version byte');
        self::assertStringContainsString(AesGcmSodiumCipher::ID, $envelope, 'algo id is embedded');
    }

    public function testGeneratedKeyIs32Bytes(): void
    {
        self::assertSame(32, \strlen($this->service->generateKey()));
    }

    public function testCallerAadMustMatch(): void
    {
        $key = $this->service->generateKey();
        $envelope = $this->service->encrypt('secret', $key, 'version-abc');

        self::assertSame('secret', $this->service->decrypt($envelope, $key, 'version-abc'));

        $this->expectException(DecryptionFailedException::class);
        $this->service->decrypt($envelope, $key, 'version-xyz');
    }

    public function testWrongKeyFails(): void
    {
        $key = $this->service->generateKey();
        $envelope = $this->service->encrypt('secret', $key);

        $this->expectException(DecryptionFailedException::class);
        $this->service->decrypt($envelope, $this->service->generateKey());
    }

    public function testTamperedCiphertextFails(): void
    {
        $key = $this->service->generateKey();
        $envelope = $this->service->encrypt('secret', $key);

        $tampered = substr($envelope, 0, -1).(\substr($envelope, -1) ^ "\x01");

        $this->expectException(DecryptionFailedException::class);
        $this->service->decrypt($tampered, $key);
    }

    public function testAlgoIdDowngradeIsRejected(): void
    {
        $key = $this->service->generateKey();
        $envelope = $this->service->encrypt('secret', $key);

        // Flip a byte inside the embedded algo id. Because the id is bound into
        // the AEAD AAD, the tag check must fail rather than silently proceed.
        $pos = strpos($envelope, AesGcmSodiumCipher::ID);
        self::assertNotFalse($pos);
        $envelope[$pos] = ($envelope[$pos] === 'A') ? 'B' : 'A';

        $this->expectException(DecryptionFailedException::class);
        $this->service->decrypt($envelope, $key);
    }

    public function testMalformedEnvelopeFails(): void
    {
        $this->expectException(DecryptionFailedException::class);
        $this->service->decrypt('garbage', $this->service->generateKey());
    }

    public function testKeyWrappingRoundTrip(): void
    {
        // The same primitive wraps keys: plaintext is another raw key.
        $kek = $this->service->generateKey();
        $dek = $this->service->generateKey();

        $wrapped = $this->service->encrypt($dek, $kek);
        self::assertSame($dek, $this->service->decrypt($wrapped, $kek));
    }
}
