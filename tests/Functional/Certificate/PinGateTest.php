<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificate;

use App\Certificate\Entity\Certificate;
use App\Certificate\Enum\CertificateStatus;
use App\Certificate\Exception\CertificateLockedException;
use App\Certificate\Exception\InvalidPinException;
use App\Certificate\Service\PinGate;
use App\Certificate\Service\PinHasher;
use App\Core\Entity\User;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

class PinGateTest extends AuthWebTestCase
{
    private function makeCertificate(User $user, string $pin = '123456'): Certificate
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $certificate = new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Test',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'test-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash($pin, \PASSWORD_ARGON2ID),
        );
        $em->persist($certificate);
        $em->flush();

        return $certificate;
    }

    public function testCorrectPinResetsCounter(): void
    {
        $user = $this->createUser($this->uniqueEmail('pin'));
        $certificate = $this->makeCertificate($user);
        $gate = static::getContainer()->get(PinGate::class);

        try {
            $gate->verify($certificate, '000000');
        } catch (InvalidPinException) {
        }
        self::assertSame(1, $certificate->getFailedPinAttempts());

        $gate->verify($certificate, '123456');
        self::assertSame(0, $certificate->getFailedPinAttempts());
    }

    /**
     * Rows from before 2026-09-21 hold Argon2id straight over the PIN. The gate
     * still accepts them and, the PIN being in hand, moves the row onto the
     * peppered form so the table alone stops confirming guesses.
     */
    public function testALegacyHashIsAcceptedOnceAndUpgradedToThePepperedForm(): void
    {
        $user = $this->createUser($this->uniqueEmail('pepper'));
        $certificate = $this->makeCertificate($user); // password_hash(): the legacy, unpeppered form
        self::assertStringStartsWith('$argon2id$', $certificate->getPinHash());

        static::getContainer()->get(PinGate::class)->verify($certificate, '123456');

        self::assertStringStartsWith(PinHasher::PREFIX, $certificate->getPinHash(), 'rehashed with the pepper');
        // And the peppered row keeps working, while a raw guess against it does not.
        static::getContainer()->get(PinGate::class)->verify($certificate, '123456');
        self::assertFalse(password_verify('123456', substr($certificate->getPinHash(), \strlen(PinHasher::PREFIX))));
    }

    public function testWrongPinReportsAttemptsRemaining(): void
    {
        $user = $this->createUser($this->uniqueEmail('pin'));
        $certificate = $this->makeCertificate($user);
        $gate = static::getContainer()->get(PinGate::class);

        $this->expectException(InvalidPinException::class);
        $this->expectExceptionMessage('4 attempt(s) remaining');
        $gate->verify($certificate, '999999');
    }

    public function testFifthFailureLocksTheCertificate(): void
    {
        $user = $this->createUser($this->uniqueEmail('pin'));
        $certificate = $this->makeCertificate($user);
        $gate = static::getContainer()->get(PinGate::class);

        for ($i = 0; $i < 4; ++$i) {
            try {
                $gate->verify($certificate, '000000');
            } catch (InvalidPinException) {
            }
        }

        try {
            $gate->verify($certificate, '000000');
            self::fail('Expected CertificateLockedException');
        } catch (CertificateLockedException) {
        }

        self::assertSame(CertificateStatus::Locked, $certificate->getStatus());

        // even the CORRECT pin is now rejected — locked means locked
        $this->expectException(CertificateLockedException::class);
        $gate->verify($certificate, '123456');
    }

    public function testUnlockRestoresAccess(): void
    {
        $user = $this->createUser($this->uniqueEmail('pin'));
        $certificate = $this->makeCertificate($user);
        $gate = static::getContainer()->get(PinGate::class);

        for ($i = 0; $i < 5; ++$i) {
            try {
                $gate->verify($certificate, '000000');
            } catch (\Throwable) {
            }
        }
        self::assertTrue($certificate->isLocked());

        $gate->unlock($certificate);

        self::assertSame(CertificateStatus::Active, $certificate->getStatus());
        self::assertSame(0, $certificate->getFailedPinAttempts());
        $gate->verify($certificate, '123456'); // no exception
    }

    public function testDesyncTripwireLocksAndAudits(): void
    {
        $user = $this->createUser($this->uniqueEmail('pin'));
        $certificate = $this->makeCertificate($user);
        $gate = static::getContainer()->get(PinGate::class);

        $gate->reportTokenPinRejected($certificate);

        self::assertTrue($certificate->isLocked());

        $count = (int) static::getContainer()->get(EntityManagerInterface::class)
            ->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM audit_log_entry WHERE action = 'certificate.pin_desync' AND severity = 'critical' AND subject_id = ?",
                [$certificate->getId()->toRfc4122()],
            );
        self::assertSame(1, $count);
    }
}
