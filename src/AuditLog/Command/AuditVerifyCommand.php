<?php

declare(strict_types=1);

namespace App\AuditLog\Command;

use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Repository\AuditLogEntryRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sigil:audit:verify',
    description: 'Verify the integrity of the hash-chained audit log',
)]
final class AuditVerifyCommand extends Command
{
    public function __construct(private readonly AuditLogEntryRepository $repository)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $expectedPrevious = AuditLogEntry::GENESIS_HASH;
        $expectedSequence = 1;
        $count = 0;

        foreach ($this->repository->iterateChain() as $entry) {
            $errors = [];
            if ($entry->getSequence() !== $expectedSequence) {
                $errors[] = sprintf('sequence gap: expected %d, found %d', $expectedSequence, $entry->getSequence());
            }
            if ($entry->getPreviousHash() !== $expectedPrevious) {
                $errors[] = 'previousHash does not match the preceding entry';
            }
            $recomputed = hash('sha256', $entry->getPreviousHash().$entry->canonicalPayload());
            if (!hash_equals($recomputed, $entry->getEntryHash())) {
                $errors[] = 'entryHash mismatch — entry content was modified';
            }

            if ([] !== $errors) {
                $io->error(sprintf(
                    "Chain BROKEN at sequence %d (action \"%s\", %s):\n - %s",
                    $entry->getSequence(),
                    $entry->getAction(),
                    $entry->getOccurredAt()->format(\DateTimeInterface::ATOM),
                    implode("\n - ", $errors),
                ));

                return Command::FAILURE;
            }

            $expectedPrevious = $entry->getEntryHash();
            $expectedSequence = $entry->getSequence() + 1;
            ++$count;
        }

        $io->success(0 === $count
            ? 'Audit log is empty — nothing to verify.'
            : sprintf('Audit chain intact: %d entries verified.', $count));

        return Command::SUCCESS;
    }
}
