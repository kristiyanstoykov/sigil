<?php

declare(strict_types=1);

namespace App\AuditLog\Command;

use App\AuditLog\Service\AuditAnchor;
use App\AuditLog\Service\AuditAnchorSigner;
use App\AuditLog\Service\AuditChainVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sigil:audit:verify',
    description: 'Verify the integrity of the hash-chained audit log',
)]
final class AuditVerifyCommand extends Command
{
    public function __construct(
        private readonly AuditChainVerifier $verifier,
        private readonly AuditAnchorSigner $anchors,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('anchor', null, InputOption::VALUE_REQUIRED, 'Anchor file written by sigil:audit:anchor; every line in it must still hold');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->verifier->verify();
        if (!$result->isIntact()) {
            $io->error(sprintf(
                "Chain BROKEN at sequence %d (action \"%s\"):\n - %s",
                $result->brokenAt,
                $result->brokenAction,
                implode("\n - ", $result->reasons),
            ));

            return Command::FAILURE;
        }
        $count = $result->entries;

        $io->success(0 === $count
            ? 'Audit log is empty - nothing to verify.'
            : sprintf('Audit chain intact: %d entries verified.', $count));

        /** @var string|null $anchorPath */
        $anchorPath = $input->getOption('anchor');

        return null === $anchorPath ? Command::SUCCESS : $this->verifyAnchors($anchorPath, $io);
    }

    /**
     * The chain being internally consistent says nothing about a tail that was
     * cut off or rewritten wholesale; the anchors do. Each one must be ours and
     * must still be found in the chain.
     */
    private function verifyAnchors(string $path, SymfonyStyle $io): int
    {
        $lines = @file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            $io->error(sprintf('Cannot read anchor file %s.', $path));

            return Command::FAILURE;
        }

        foreach ($lines as $n => $line) {
            try {
                $anchor = AuditAnchor::fromJsonLine($line);
            } catch (\Throwable) {
                $io->error(sprintf('Anchor line %d is not a valid anchor.', $n + 1));

                return Command::FAILURE;
            }
            if (!$this->anchors->isAuthentic($anchor)) {
                $io->error(sprintf('Anchor line %d (sequence %d) was not minted by this installation, or was altered.', $n + 1, $anchor->sequence));

                return Command::FAILURE;
            }
            if (!$this->anchors->stillHolds($anchor)) {
                $io->error(sprintf(
                    'Anchor line %d says sequence %d had hash %s… at %s - the chain no longer contains it (tail deleted or history rewritten).',
                    $n + 1,
                    $anchor->sequence,
                    substr($anchor->entryHash, 0, 12),
                    $anchor->anchoredAt->format(\DateTimeInterface::ATOM),
                ));

                return Command::FAILURE;
            }
        }

        $io->success(sprintf('%d anchor(s) hold: the chain still contains every checkpointed head.', \count($lines)));

        return Command::SUCCESS;
    }
}
