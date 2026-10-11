<?php

declare(strict_types=1);

namespace App\Account\Message;

use App\Account\Repository\DataExportRepository;
use App\Account\Service\DataExportBuilder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/** The builder records its own failure, so nothing is rethrown for a retry. */
#[AsMessageHandler]
final readonly class BuildDataExportHandler
{
    public function __construct(
        private DataExportRepository $exports,
        private DataExportBuilder $builder,
    ) {}

    public function __invoke(BuildDataExport $message): void
    {
        $export = Uuid::isValid($message->exportId) ? $this->exports->find(Uuid::fromString($message->exportId)) : null;
        if (null !== $export) {
            $this->builder->build($export);
        }
    }
}
