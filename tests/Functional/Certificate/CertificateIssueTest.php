<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificate;

use App\Certificate\Entity\Certificate;
use App\Certificate\Enum\CertificateStatus;
use App\Certificate\Service\CertificateIssuer;
use App\Certificate\Service\Pkcs11TokenManager;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Tests\Functional\AuthWebTestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises the real chain: SoftHSM token init, in-token keygen, CA-signed
 * cert via the Python driver. Requires an initialized CA (sigil:ca:init).
 */
class CertificateIssueTest extends AuthWebTestCase
{
    /** @var list<string> */
    private array $tokensToCleanUp = [];

    protected function tearDown(): void
    {
        $manager = static::getContainer()->get(Pkcs11TokenManager::class);
        foreach ($this->tokensToCleanUp as $label) {
            try {
                $manager->deleteToken($label);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    public function testIssueProducesCaSignedCertificateWithInTokenKey(): void
    {
        $user = $this->createUser($this->uniqueEmail('cert'));
        $issuer = static::getContainer()->get(CertificateIssuer::class);

        $certificate = $issuer->issueForUser($user, '123456');
        $this->tokensToCleanUp[] = $certificate->getTokenLabel();

        self::assertSame(CertificateStatus::Active, $certificate->getStatus());
        self::assertSame('ECDSA-P384-SHA384/v1', $certificate->getAlgorithmId());
        self::assertStringContainsString('BEGIN CERTIFICATE', $certificate->getCertificatePem());
        self::assertNotSame($certificate->getPinHash(), '123456');
        self::assertTrue(password_verify('123456', $certificate->getPinHash()));

        // the certificate chains to the Sigil CA
        $pemFile = (string) tempnam(sys_get_temp_dir(), 'sigil-test-cert-');
        file_put_contents($pemFile, $certificate->getCertificatePem());
        $verify = new Process(['openssl', 'verify', '-CAfile', 'var/ca/ca.crt', $pemFile], cwd: '/app');
        $verify->run();
        @unlink($pemFile);
        self::assertStringContainsString('OK', $verify->getOutput(), $verify->getErrorOutput());

        // and its token really exists, holding the (non-exportable) key
        $manager = static::getContainer()->get(Pkcs11TokenManager::class);
        self::assertTrue($manager->tokenExists($certificate->getTokenLabel()));
    }

    public function testPinFormatIsEnforced(): void
    {
        $user = $this->createUser($this->uniqueEmail('cert'));
        $issuer = static::getContainer()->get(CertificateIssuer::class);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PIN must be 6 to 8 digits');
        $issuer->issueForUser($user, 'abc123');
    }

    public function testRevokeDeletesTheToken(): void
    {
        $user = $this->createUser($this->uniqueEmail('cert'));
        $issuer = static::getContainer()->get(CertificateIssuer::class);
        $manager = static::getContainer()->get(Pkcs11TokenManager::class);

        $certificate = $issuer->issueForUser($user, '654321');
        self::assertTrue($manager->tokenExists($certificate->getTokenLabel()));

        $issuer->revoke($certificate, $user, 'user requested');

        self::assertSame(CertificateStatus::Revoked, $certificate->getStatus());
        self::assertFalse($manager->tokenExists($certificate->getTokenLabel()));
    }
}
