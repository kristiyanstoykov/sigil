<?php

declare(strict_types=1);

namespace App\AuditLog\Command;

use App\AuditLog\Service\AuditAnchorSigner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checkpoint the audit chain head to somewhere the database cannot reach.
 *
 * A hash chain inside one database is tamper-evident only against edits in
 * the middle: whoever can write the table can rewrite the tail and recompute,
 * or drop it outright, and the verifier has nothing to compare against. This
 * writes a MAC'd JSON line naming the head; keep the file off the host (cron
 * it to object storage or a WORM bucket) and `sigil:audit:verify --anchor`
 * checks the chain still contains what it said.
 */
#[AsCommand(name: 'sigil:audit:anchor', description: 'Write a signed checkpoint of the audit chain head to a file outside the database')]
final class AuditAnchorCommand extends Command
{
    public function __construct(
        private readonly AuditAnchorSigner $signer,
        #[Autowire('%env(resolve:SIGIL_AUDIT_ANCHOR_PATH)%')]
        private readonly string $defaultPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Anchor file to append to (default: SIGIL_AUDIT_ANCHOR_PATH)')
            ->addOption('stdout', null, InputOption::VALUE_NONE, 'Print the anchor line instead of writing a file (for shipping elsewhere)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $anchor = $this->signer->anchorHead();
        if (null === $anchor) {
            $io->note('Audit log is empty - nothing to anchor.');

            return Command::SUCCESS;
        }

        if ($input->getOption('stdout')) {
            $output->write($anchor->toJsonLine());

            return Command::SUCCESS;
        }

        /** @var string $path */
        $path = $input->getOption('path') ?? $this->defaultPath;
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            $io->error(sprintf('Cannot create %s.', $dir));

            return Command::FAILURE;
        }
        if (false === file_put_contents($path, $anchor->toJsonLine(), \FILE_APPEND | \LOCK_EX)) {
            $io->error(sprintf('Cannot write %s.', $path));

            return Command::FAILURE;
        }

        $io->success(sprintf('Anchored sequence %d (%s…) to %s.', $anchor->sequence, substr($anchor->entryHash, 0, 12), $path));

        return Command::SUCCESS;
    }
}
