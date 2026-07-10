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
 * Creates the bucket for each S3-compatible backend if it does not exist
 * (idempotent). Run once after bringing up MinIO, or when pointing a backend at
 * a fresh AWS bucket. Non-S3 backends (local filesystem) need no bootstrap.
 */
#[AsCommand(
    name: 'sigil:storage:init',
    description: 'Ensure the object-storage bucket exists for each configured S3 backend',
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
        $touched = 0;

        foreach ($this->registry->all() as $backend) {
            if (!$backend instanceof S3StorageBackend) {
                continue;
            }

            try {
                $backend->ensureBucket();
            } catch (DomainException $e) {
                $io->warning(sprintf('[%s] %s (backend skipped - likely not configured)', $backend->id(), $e->getMessage()));

                continue;
            }

            $io->writeln(sprintf(' <info>✓</info> [%s] bucket "%s" ready', $backend->id(), $backend->bucket()));
            ++$touched;
        }

        $io->success(sprintf('Storage init complete (%d S3 backend(s) ready).', $touched));

        return Command::SUCCESS;
    }
}
