<?php

declare(strict_types=1);

namespace App\Certificate\Repository;

use App\Certificate\Entity\Certificate;
use App\Certificate\Enum\CertificateStatus;
use App\Core\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Certificate>
 */
class CertificateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Certificate::class);
    }

    /**
     * @return list<Certificate>
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    public function countActiveForUser(User $user): int
    {
        return $this->count(['user' => $user, 'status' => [CertificateStatus::Active, CertificateStatus::Locked]]);
    }

    public function userHasUsableCertificate(User $user): bool
    {
        return $this->count(['user' => $user, 'status' => CertificateStatus::Active]) > 0;
    }

    /**
     * ADR-008: atomic, guarded failure counting — a single UPDATE so
     * concurrent wrong-PIN requests cannot race past the limit. Resets the
     * window when the last failure is older than PIN_WINDOW_SECONDS, locks
     * the certificate when the limit is reached.
     *
     * @return array{failed_pin_attempts: int, status: string} the post-update state
     */
    public function registerFailedPinAttempt(Certificate $certificate, \DateTimeImmutable $now): array
    {
        $conn = $this->getEntityManager()->getConnection();

        /** @var array{failed_pin_attempts: int, status: string} $row */
        $row = $conn->fetchAssociative(
            <<<'SQL'
                UPDATE certificate SET
                    failed_pin_attempts = CASE
                        WHEN last_failed_pin_at IS NULL OR last_failed_pin_at < :window_start THEN 1
                        ELSE failed_pin_attempts + 1
                    END,
                    last_failed_pin_at = :now,
                    status = CASE
                        WHEN (CASE
                            WHEN last_failed_pin_at IS NULL OR last_failed_pin_at < :window_start THEN 1
                            ELSE failed_pin_attempts + 1
                        END) >= :max_attempts THEN 'locked'
                        ELSE status
                    END,
                    locked_at = CASE
                        WHEN (CASE
                            WHEN last_failed_pin_at IS NULL OR last_failed_pin_at < :window_start THEN 1
                            ELSE failed_pin_attempts + 1
                        END) >= :max_attempts THEN :now
                        ELSE locked_at
                    END
                WHERE id = :id AND status = 'active'
                RETURNING failed_pin_attempts, status
                SQL,
            [
                'id' => $certificate->getId()->toRfc4122(),
                'now' => $now->format('Y-m-d H:i:s'),
                'window_start' => $now->modify(sprintf('-%d seconds', Certificate::PIN_WINDOW_SECONDS))->format('Y-m-d H:i:s'),
                'max_attempts' => Certificate::MAX_PIN_ATTEMPTS,
            ],
        ) ?: ['failed_pin_attempts' => Certificate::MAX_PIN_ATTEMPTS, 'status' => CertificateStatus::Locked->value];

        // keep the in-memory entity consistent with what the DB just decided
        $this->getEntityManager()->refresh($certificate);

        return $row;
    }
}
