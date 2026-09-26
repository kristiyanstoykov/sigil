<?php

declare(strict_types=1);

namespace App\Document\Command;

use App\Core\Exception\DomainException;
use App\Document\Service\S3StorageBackend;
use App\Document\Service\StorageBackendRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the active backend's bucket if it is S3-compatible and missing
 * (idempotent). Only the active one: it is the only one written to, and an
 * inactive backend may be unconfigured on purpose (no MinIO in prod).
 */
#[AsCommand(
    name: 'sigil:storage:init',
    description: 'Ensure the object-storage bucket exists for the active S3 backend',
)]
final class StorageInitCommand extends Command
{
    public function __construct(private readonly StorageBackendRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $backend = $this->registry->active();

        if (!$backend instanceof S3StorageBackend) {
            $io->success(sprintf('[%s] is not S3-compatible; nothing to initialise.', $backend->id()));

            return Command::SUCCESS;
        }

        try {
            $backend->ensureBucket();
        } catch (DomainException $e) {
            $io->error(sprintf('[%s] %s', $backend->id(), $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('[%s] bucket "%s" ready.', $backend->id(), $backend->bucket()));

        return Command::SUCCESS;
    }
}
