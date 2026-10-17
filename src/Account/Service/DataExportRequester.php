<?php

declare(strict_types=1);

namespace App\Account\Service;

use App\Account\Entity\DataExport;
use App\Account\Message\BuildDataExport;
use App\Account\Repository\DataExportRepository;
use App\AuditLog\AuditLoggerInterface;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Queues a data export; the worker builds it (ADR-015 §7). */
final class DataExportRequester
{
    public function __construct(
        private readonly DataExportRepository $exports,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
    }

    /**
     * @throws DomainException while an earlier export is still being built
     */
    public function request(User $owner): DataExport
    {
        if ($this->exports->hasInProgress($owner)) {
            throw new DomainException('Your previous export is still being prepared.');
        }

        $export = new DataExport($owner);
        $this->em->persist($export);
        $this->em->flush();

        $this->auditLogger->log(
            action: 'data_export.requested',
            actor: $owner,
            subjectType: 'DataExport',
            subjectId: $export->getId()->toRfc4122(),
        );

        // After the flush: the worker must find the row it is handed.
        $this->bus->dispatch(new BuildDataExport($export->getId()->toRfc4122()));

        return $export;
    }
}
