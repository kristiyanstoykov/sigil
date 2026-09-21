<?php

declare(strict_types=1);

namespace App\AuditLog\Command;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Enum\AuditSeverity;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\AuditLog\Service\AuditChainHasher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repair a chain whose hashes cannot be recomputed from what the database
 * kept. Until 2026-09-21 entries were hashed over a timestamp with
 * microseconds that the timestamp(0) column dropped, so every entry written
 * before then fails `sigil:audit:verify` on reload although nothing was
 * tampered with. This recomputes each hash over the stored content, relinks
 * the chain, and records that it did so - the old head hash goes into the
 * payload of a final `audit.rechained` entry, so the repair is on the record.
 *
 * Refuses to run on a chain that verifies: there is nothing to repair, and a
 * rehash of a good chain would be exactly the rewrite the log exists to expose.
 */
#[AsCommand(name: 'sigil:audit:rechain', description: 'Recompute the audit chain hashes over stored content (pre-2026-09-21 microsecond bug); audited, refuses an intact chain')]
final class AuditRechainCommand extends Command
{
    public function __construct(
        private readonly AuditLogEntryRepository $repository,
        private readonly AuditLoggerInterface $auditLogger,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Actually rewrite the hashes (without it: report only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $head = $this->repository->findChainHead();
        if (null === $head) {
            $io->note('Audit log is empty.');

            return Command::SUCCESS;
        }

        $broken = 0;
        $expectedPrevious = AuditLogEntry::GENESIS_HASH;
        foreach ($this->repository->iterateChain() as $entry) {
            $recomputed = AuditChainHasher::hash($entry->getHashScheme(), $entry->getPreviousHash(), $entry->canonicalPayload());
            if ($entry->getPreviousHash() !== $expectedPrevious || !hash_equals($recomputed, $entry->getEntryHash())) {
                ++$broken;
            }
            $expectedPrevious = $entry->getEntryHash();
        }

        if (0 === $broken) {
            $io->success('The chain verifies as it is - nothing to repair, and a good chain is never rehashed.');

            return Command::SUCCESS;
        }
        if (!$input->getOption('confirm')) {
            $io->warning(sprintf('%d of %d entries do not verify. Re-run with --confirm to relink the chain over the stored content.', $broken, $head->getSequence()));

            return Command::FAILURE;
        }

        $oldHead = $head->getEntryHash();
        $previous = AuditLogEntry::GENESIS_HASH;
        $count = 0;
        $this->em->wrapInTransaction(function () use (&$previous, &$count): void {
            foreach ($this->repository->iterateChain() as $entry) {
                $entry->relink($previous);
                $previous = $entry->getEntryHash();
                ++$count;
            }
        });
        $this->em->clear();

        $this->auditLogger->log(
            action: 'audit.rechained',
            payload: ['entries' => $count, 'previousHeadHash' => $oldHead, 'newHeadHash' => $previous, 'reason' => 'pre-2026-09-21 microsecond hashing bug'],
            severity: AuditSeverity::Warning,
        );

        $io->success(sprintf('Relinked %d entries (old head %s…, new head %s…); the repair is recorded as audit.rechained. Anchor the new head now.', $count, substr($oldHead, 0, 12), substr($previous, 0, 12)));

        return Command::SUCCESS;
    }
}
