<?php

declare(strict_types=1);

namespace App\Certificate\Service;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Entity\Certificate;
use App\Certificate\Repository\CertificateRepository;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * Issues per-user X.509 certificates: fresh PKCS#11 token, in-token keypair,
 * CA-signed certificate via the Python driver (bin/issue_cert.py). The CA
 * key never leaves its token; the user key never leaves the new token; the
 * PIN is hashed (Argon2id) and discarded (ADR-005/ADR-008).
 */
class CertificateIssuer
{
    private const string KEY_LABEL = 'sign';
    private const string KEY_ID = '01';
    private const int USER_CERT_DAYS = 365;
    public const int CA_CERT_DAYS = 1825; // 5 years

    public function __construct(
        private readonly Pkcs11TokenManager $tokens,
        private readonly SignatureAlgorithmRegistry $algorithms,
        private readonly CertificateRepository $certificates,
        private readonly EntityManagerInterface $em,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ClockInterface $clock,
        #[Autowire(env: 'PKCS11_MODULE')]
        private readonly string $modulePath,
        #[Autowire(env: 'SIGIL_CA_PIN')]
        private readonly string $caPin,
        #[Autowire('%kernel.project_dir%/bin/issue_cert.py')]
        private readonly string $driverPath,
        #[Autowire('%kernel.project_dir%/var/ca/ca.crt')]
        private readonly string $caCertPath,
        private readonly string $caTokenLabel = 'sigil-ca',
    ) {
    }

    public function issueForUser(User $user, #[\SensitiveParameter] string $pin): Certificate
    {
        self::assertValidPin($pin);

        if ($this->certificates->countActiveForUser($user) >= Certificate::MAX_PER_USER) {
            throw new DomainException(sprintf('You already have the maximum of %d certificates.', Certificate::MAX_PER_USER));
        }
        if (!is_file($this->caCertPath)) {
            throw new DomainException('The certificate authority is not initialized (run sigil:ca:init).');
        }

        $algorithm = $this->algorithms->default();
        $tokenLabel = 'crt-'.Uuid::v7()->toBase32();

        try {
            $this->tokens->initToken($tokenLabel, $pin);
            $this->tokens->generateKeyPair($tokenLabel, $algorithm->pkcs11KeyType(), self::KEY_LABEL, self::KEY_ID, $pin);

            $result = $this->runDriver([
                'mode' => 'issue',
                'module' => $this->modulePath,
                'signer' => [
                    'token_label' => $this->caTokenLabel,
                    'key_label' => self::KEY_LABEL,
                    'pin' => $this->caPin,
                ],
                'subject' => $this->subjectFor($user),
                'validity_days' => self::USER_CERT_DAYS,
                'issuer_cert_pem' => (string) file_get_contents($this->caCertPath),
                'subject_pubkey' => [
                    'token_label' => $tokenLabel,
                    'key_label' => self::KEY_LABEL,
                ],
            ]);

            $this->tokens->writeCertificate(
                $tokenLabel,
                self::pemToDer($result['certificate_pem']),
                self::KEY_LABEL,
                self::KEY_ID,
                $pin,
            );

            $certificate = new Certificate(
                user: $user,
                serialNumber: $result['serial_number'],
                subjectDn: $result['subject_dn'],
                certificatePem: $result['certificate_pem'],
                notBefore: new \DateTimeImmutable($result['not_before']),
                notAfter: new \DateTimeImmutable($result['not_after']),
                algorithmId: $algorithm->id(),
                tokenLabel: $tokenLabel,
                keyLabel: self::KEY_LABEL,
                pinHash: password_hash($pin, \PASSWORD_ARGON2ID),
            );

            $this->em->persist($certificate);
            $this->em->flush();
        } catch (\Throwable $e) {
            // never leave an orphaned token with a live keypair behind
            try {
                $this->tokens->deleteToken($tokenLabel);
            } catch (\Throwable) {
                // token may not exist yet; the original failure matters more
            }

            throw $e;
        }

        $this->auditLogger->log(
            action: 'certificate.issued',
            actor: $user,
            payload: [
                'serialNumber' => $certificate->getSerialNumber(),
                'subjectDn' => $certificate->getSubjectDn(),
                'algorithm' => $algorithm->id(),
                'notAfter' => $certificate->getNotAfter()->format(\DateTimeInterface::ATOM),
            ],
            subjectType: 'Certificate',
            subjectId: $certificate->getId()->toRfc4122(),
        );

        return $certificate;
    }

    public function revoke(Certificate $certificate, User $actor, string $reason): void
    {
        if (null !== $certificate->getRevokedAt()) {
            throw new DomainException('This certificate is already revoked.');
        }

        $certificate->revoke($this->now(), $reason);
        $this->em->flush();
        $this->tokens->deleteToken($certificate->getTokenLabel());

        $this->auditLogger->log(
            action: 'certificate.revoked',
            actor: $actor,
            payload: ['serialNumber' => $certificate->getSerialNumber(), 'reason' => $reason],
            subjectType: 'Certificate',
            subjectId: $certificate->getId()->toRfc4122(),
            severity: AuditSeverity::Warning,
        );
    }

    /**
     * CA bootstrap (sigil:ca:init). Refuses to touch an existing CA.
     */
    public function bootstrapCa(): string
    {
        if (is_file($this->caCertPath)) {
            throw new DomainException('CA already initialized — refusing to overwrite.');
        }

        // token survives (named volume) but var/ was wiped: re-export the
        // CA cert stored in the token instead of failing or re-keying
        if ($this->tokens->tokenExists($this->caTokenLabel)) {
            $der = $this->tokens->readCertificate($this->caTokenLabel, self::KEY_LABEL);
            $this->writeCaCertFile(self::derToPem($der));

            return $this->caCertPath;
        }

        $algorithm = $this->algorithms->default();
        $this->tokens->initToken($this->caTokenLabel, $this->caPin);
        $this->tokens->generateKeyPair($this->caTokenLabel, $algorithm->pkcs11KeyType(), self::KEY_LABEL, self::KEY_ID, $this->caPin);

        $result = $this->runDriver([
            'mode' => 'ca-selfsign',
            'module' => $this->modulePath,
            'signer' => [
                'token_label' => $this->caTokenLabel,
                'key_label' => self::KEY_LABEL,
                'pin' => $this->caPin,
            ],
            'subject' => [
                'country_name' => 'BG',
                'organization_name' => 'Sigil',
                'common_name' => 'Sigil Signum Veritatis CA',
            ],
            'validity_days' => self::CA_CERT_DAYS,
        ]);

        // store the cert in the token too, so the PEM file can always be
        // re-exported if var/ is wiped (container recreation)
        $this->tokens->writeCertificate(
            $this->caTokenLabel,
            self::pemToDer($result['certificate_pem']),
            self::KEY_LABEL,
            self::KEY_ID,
            $this->caPin,
        );
        $this->writeCaCertFile($result['certificate_pem']);

        $this->auditLogger->log(
            action: 'certificate.ca_initialized',
            payload: ['serialNumber' => $result['serial_number'], 'subjectDn' => $result['subject_dn']],
            severity: AuditSeverity::Warning,
        );

        return $this->caCertPath;
    }

    public static function assertValidPin(#[\SensitiveParameter] string $pin): void
    {
        if (1 !== preg_match('/^\d{6,8}$/', $pin)) {
            throw new DomainException('The PIN must be 6 to 8 digits.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function subjectFor(User $user): array
    {
        return array_filter([
            'country_name' => 'BG',
            'organization_name' => $user->getCompany(),
            'organizational_unit_name' => $user->getPosition(),
            'common_name' => trim($user->getFirstName().' '.$user->getLastName()),
        ]);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array{certificate_pem: string, serial_number: string, subject_dn: string, not_before: string, not_after: string}
     */
    private function runDriver(array $request): array
    {
        $process = new Process(['python3', $this->driverPath]);
        $process->setInput(json_encode($request, \JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->run();

        /** @var mixed $decoded */
        $decoded = json_decode($process->getOutput(), true);

        if (!\is_array($decoded) || true !== ($decoded['ok'] ?? false)) {
            $error = \is_array($decoded) && \is_string($decoded['error'] ?? null)
                ? $decoded['error']
                : 'driver produced no output';
            $this->auditLogger->log(
                action: 'certificate.issuance_failed',
                payload: ['error' => $error],
                severity: AuditSeverity::Critical,
            );

            throw new DomainException('Certificate issuance failed.');
        }

        /** @var array{certificate_pem: string, serial_number: string, subject_dn: string, not_before: string, not_after: string} $decoded */
        return $decoded;
    }

    private function writeCaCertFile(string $pem): void
    {
        $dir = \dirname($this->caCertPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new DomainException('Could not create CA directory.');
        }
        file_put_contents($this->caCertPath, $pem);
    }

    private static function derToPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END CERTIFICATE-----\n";
    }

    private static function pemToDer(string $pem): string
    {
        $base64 = preg_replace('/-----[^-]+-----|\s/', '', $pem);
        $der = base64_decode((string) $base64, true);
        if (false === $der) {
            throw new DomainException('Driver returned an invalid certificate PEM.');
        }

        return $der;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
