<?php

declare(strict_types=1);

namespace App\Certificate\Service;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Certificate\Entity\Certificate;
use App\Certificate\Enum\CertificateStatus;
use App\Certificate\Exception\CertificateLockedException;
use App\Certificate\Exception\InvalidPinException;
use App\Certificate\Repository\CertificateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * ADR-008: the hash-first PIN gate. The DB is the single live lockout
 * counter; the PKCS#11 token never sees a wrong PIN. Call order per attempt:
 * rate limiter (controller) → this gate → open PKCS#11 session.
 *
 * The desync tripwire (hash matched but the token later rejected the PIN)
 * is reported back via reportTokenPinRejected() - integrity alarm, fail closed.
 */
class PinGate
{
    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly EntityManagerInterface $em,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Verifies the PIN or throws. On success the counter is reset and the
     * caller may open the PKCS#11 session with the (still in-memory) PIN.
     *
     * @throws CertificateLockedException when the certificate is locked/unusable
     * @throws InvalidPinException        on a wrong PIN (message includes attempts remaining)
     */
    public function verify(Certificate $certificate, #[\SensitiveParameter] string $pin): void
    {
        $now = $this->now();

        if (!$certificate->isUsable($now)) {
            throw new CertificateLockedException($certificate->isLocked()
                ? 'This certificate is locked. Unlock it with your password and a fresh authenticator code.'
                : 'This certificate is not usable (revoked or expired).');
        }

        if (password_verify($pin, $certificate->getPinHash())) {
            $certificate->resetPinCounter();
            $this->em->flush();

            return;
        }

        $state = $this->certificates->registerFailedPinAttempt($certificate, $now);
        $locked = CertificateStatus::Locked->value === $state['status'];

        $this->auditLogger->log(
            action: $locked ? 'certificate.pin_locked' : 'certificate.pin_failed',
            actor: $certificate->getUser(),
            payload: ['failedAttempts' => $state['failed_pin_attempts']],
            subjectType: 'Certificate',
            subjectId: $certificate->getId()->toRfc4122(),
            severity: $locked ? AuditSeverity::Warning : AuditSeverity::Info,
        );

        if ($locked) {
            throw new CertificateLockedException('Too many failed attempts - this certificate is now locked.');
        }

        throw new InvalidPinException(sprintf(
            'Incorrect PIN. %d attempt(s) remaining.',
            max(0, Certificate::MAX_PIN_ATTEMPTS - $state['failed_pin_attempts']),
        ));
    }

    /**
     * ADR-008 desync tripwire: the Argon2id hash matched but the token
     * rejected the PIN. Hash and token have desynchronized - lock the
     * certificate, audit at high severity, require re-issue. Fail closed.
     */
    public function reportTokenPinRejected(Certificate $certificate): void
    {
        $certificate->lock($this->now());
        $this->em->flush();

        $this->auditLogger->log(
            action: 'certificate.pin_desync',
            actor: $certificate->getUser(),
            payload: ['tokenLabel' => $certificate->getTokenLabel()],
            subjectType: 'Certificate',
            subjectId: $certificate->getId()->toRfc4122(),
            severity: AuditSeverity::Critical,
        );
    }

    /**
     * ADR-008 unlock: the controller must have re-proven password + fresh
     * TOTP before calling this. Key and certificate are untouched.
     */
    public function unlock(Certificate $certificate): void
    {
        if (!$certificate->isLocked()) {
            return;
        }

        $certificate->unlock();
        $this->em->flush();

        $this->auditLogger->log(
            action: 'certificate.unlocked',
            actor: $certificate->getUser(),
            subjectType: 'Certificate',
            subjectId: $certificate->getId()->toRfc4122(),
            severity: AuditSeverity::Warning,
        );
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone('UTC'));
    }
}
