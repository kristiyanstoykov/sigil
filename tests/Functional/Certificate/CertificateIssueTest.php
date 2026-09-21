<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificate;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Certificate\Algorithm\MlDsa65;
use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Entity\Certificate;
use App\Certificate\Enum\CertificateStatus;
use App\Certificate\Service\CertificateIssuer;
use App\Certificate\Repository\CertificateRepository;
use App\Certificate\Service\PinHasher;
use App\Certificate\Service\Pkcs11TokenManager;
use App\Certificate\Service\SuiteCredentials;
use App\Core\Entity\User;
use App\Core\Process\JsonDriver;
use App\Core\Exception\DomainException;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Process\Process;

/**
 * Exercises the real chain: kryoptic token init, in-token keygen, CA-signed
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
        // Peppered under the host key, so a raw guess against the stored hash proves nothing.
        self::assertStringStartsWith(PinHasher::PREFIX, $certificate->getPinHash());
        self::assertTrue(static::getContainer()->get(PinHasher::class)->verify('123456', $certificate->getPinHash()));

        // the certificate chains to the Sigil CA
        $pemFile = (string) tempnam(sys_get_temp_dir(), 'sigil-test-cert-');
        file_put_contents($pemFile, $certificate->getCertificatePem());
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $verify = new Process(['openssl', 'verify', '-CAfile', 'var/ca/ca.crt', $pemFile], cwd: $projectDir);
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

    /**
     * Revocation is a decision, token deletion is housekeeping. If the token
     * cannot be destroyed the certificate is revoked all the same, the decision
     * is on the audit log, and the cleanup failure is on it too.
     */
    public function testRevocationHoldsAndIsAuditedEvenWhenTheTokenCannotBeDeleted(): void
    {
        $c = static::getContainer();
        $user = $this->createUser($this->uniqueEmail('cert'));
        $certificate = $c->get(CertificateIssuer::class)->issueForUser($user, '654321');
        $this->tokensToCleanUp[] = $certificate->getTokenLabel();

        $brokenTokens = new class((string) getenv('PKCS11_MODULE')) extends Pkcs11TokenManager {
            public function deleteToken(string $tokenLabel): void
            {
                throw new DomainException('PKCS#11 slot registry "remove" failed (exit 1).');
            }
        };
        $issuer = new CertificateIssuer(
            $brokenTokens,
            $c->get(SignatureAlgorithmRegistry::class),
            $c->get(CertificateRepository::class),
            $c->get(EntityManagerInterface::class),
            $c->get(AuditLoggerInterface::class),
            $c->get(ClockInterface::class),
            (string) getenv('PKCS11_MODULE'),
            (string) ($_ENV['SIGIL_CA_PIN'] ?? $_SERVER['SIGIL_CA_PIN']),
            (string) ($_ENV['SIGIL_SEAL_PIN'] ?? $_SERVER['SIGIL_SEAL_PIN']),
            new JsonDriver($c->getParameter('kernel.project_dir').'/bin'),
            new SuiteCredentials($c->getParameter('kernel.project_dir').'/var/ca'),
        $c->get(PinHasher::class),
        );

        $issuer->revoke($certificate, $user, 'user requested');

        self::assertSame(CertificateStatus::Revoked, $certificate->getStatus());
        self::assertTrue($c->get(Pkcs11TokenManager::class)->tokenExists($certificate->getTokenLabel()), 'the token is still there');

        $actions = array_map(
            static fn ($entry) => $entry->getAction(),
            $c->get(AuditLogEntryRepository::class)->findForSubject('Certificate', $certificate->getId()->toRfc4122()),
        );
        self::assertContains('certificate.revoked', $actions);
        self::assertContains('certificate.token_cleanup_failed', $actions);
    }

    /**
     * ADR-014: the post-quantum suite's key pair is generated inside the token
     * by bin/keygen.py, since pkcs11-tool cannot. The private key never leaves
     * it - only the public half is readable without a PIN.
     */
    public function testAnMlDsaKeyPairCanBeGeneratedInsideTheToken(): void
    {
        $manager = static::getContainer()->get(Pkcs11TokenManager::class);
        $label = 'test-mldsa-'.bin2hex(random_bytes(4));
        $this->tokensToCleanUp[] = $label;

        $manager->initToken($label, '654321');
        $manager->generateKeyPair($label, new MlDsa65(), 'sign', '01', '654321');

        $process = new Process(['pkcs11-tool', '--module', (string) getenv('PKCS11_MODULE'), '--token-label', $label, '--list-objects']);
        $process->mustRun();
        self::assertStringContainsString('Public Key Object', $process->getOutput());
        self::assertStringNotContainsString('Private Key Object', $process->getOutput(), 'the private key is not visible without a login');

        $manager->deleteToken($label);
        self::assertFalse($manager->tokenExists($label));
    }

    public function testDeletingATokenTwiceIsNotAnError(): void
    {
        $manager = static::getContainer()->get(Pkcs11TokenManager::class);
        $label = 'test-gone-'.bin2hex(random_bytes(4));

        $manager->deleteToken($label);
        self::assertFalse($manager->tokenExists($label));
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
