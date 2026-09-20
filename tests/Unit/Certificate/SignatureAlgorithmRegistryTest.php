<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificate;

use App\Certificate\Algorithm\EcdsaP384Sha384;
use App\Certificate\Algorithm\MlDsa65;
use App\Certificate\Algorithm\SignatureAlgorithmInterface;
use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Algorithm\SigningMode;
use App\Core\Exception\DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The suite registry follows the storage-backend model (ADR-014): the active
 * suite is a deployment setting for new keys, and every suite stays resolvable
 * by the id stamped on existing certificates.
 */
final class SignatureAlgorithmRegistryTest extends TestCase
{
    public function testTheActiveSuiteComesFromTheEnvironmentAndBothStayResolvable(): void
    {
        $registry = new SignatureAlgorithmRegistry([new EcdsaP384Sha384(), new MlDsa65()], MlDsa65::ID);

        self::assertSame(MlDsa65::ID, $registry->active()->id());
        // Switching the active suite must not orphan a certificate issued under the other.
        self::assertSame('ecdsa', $registry->get(EcdsaP384Sha384::ID)->family());
        self::assertCount(2, $registry->all());
    }

    public function testAnUnknownActiveSuiteFailsClosedAtConstruction(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Unknown signature algorithm "RSA-3072-SHA384/v1"');

        new SignatureAlgorithmRegistry([new EcdsaP384Sha384()], 'RSA-3072-SHA384/v1');
    }

    /**
     * The driver spec is the contract with bin/*.py - pin its exact shape.
     *
     * @param array<string, string> $expected
     */
    #[DataProvider('specs')]
    public function testTheDriverSpecCarriesEverythingTheDriversDispatchOn(SignatureAlgorithmInterface $suite, array $expected): void
    {
        self::assertSame($expected, $suite->toDriverSpec());
    }

    /** @return iterable<string, array{SignatureAlgorithmInterface, array<string, string>}> */
    public static function specs(): iterable
    {
        yield 'ecdsa' => [new EcdsaP384Sha384(), [
            'spec' => 'v1',
            'id' => 'ECDSA-P384-SHA384/v1',
            'family' => 'ecdsa',
            'parameter_set' => 'secp384r1',
            'digest' => 'sha384',
            'signing_mode' => 'prehash',
            'signature_algorithm' => 'sha384_ecdsa',
        ]];
        yield 'ml-dsa' => [new MlDsa65(), [
            'spec' => 'v1',
            'id' => 'ML-DSA-65/v1',
            'family' => 'ml-dsa',
            'parameter_set' => 'ML-DSA-65',
            'digest' => 'sha384',
            'signing_mode' => 'pure',
            'signature_algorithm' => 'mldsa65',
        ]];
    }

    public function testMlDsaIsNeverPrehashed(): void
    {
        // Feeding an ML-DSA key a digest signs the wrong bytes (RFC 9882).
        self::assertSame(SigningMode::Pure, (new MlDsa65())->signingMode());
        self::assertSame(SigningMode::Prehash, (new EcdsaP384Sha384())->signingMode());
    }
}
