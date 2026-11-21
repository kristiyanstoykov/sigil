<?php

declare(strict_types=1);

namespace App\Account\Command;

use App\Account\Repository\ArchivedUserKeyRepository;
use App\Account\Service\RetentionPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Erases deleted accounts past their ten-year retention (ADR-015 §6). Meant for
 * the worker, daily (RetentionSweepSchedule). Fault-isolated like sigil:signing:sweep: one account that fails
 * is logged and skipped, and the exit code says so.
 */
#[AsCommand(
    name: 'sigil:retention:sweep',
    description: 'Erase deleted accounts whose retention period has ended',
)]
final class RetentionSweepCommand extends Command
{
    public function __construct(
        private readonly ArchivedUserKeyRepository $archivedKeys,
        private readonly RetentionPurger $purger,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be erased without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $due = $this->archivedKeys->findPastRetention($this->clock->now());
        if ([] === $due) {
            $io->success('No account is past its retention period.');

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $io->note(sprintf('%d account(s) past retention. Nothing was changed.', \count($due)));

            return Command::SUCCESS;
        }

        $purged = 0;
        $failed = 0;
        $ids = array_map(static fn ($key): string => $key->getId()->toRfc4122(), $due);
        foreach ($ids as $id) {
            $key = $this->archivedKeys->find($id);
            if (null === $key) {
                continue; // restored since the query
            }
            $userId = $key->getUser()->getId()->toRfc4122();

            try {
                $this->purger->purge($key);
                ++$purged;
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Retention purge failed for an account; continuing with the rest.', ['userId' => $userId, 'exception' => $e]);
                $io->warning(sprintf('Failed on account %s: %s', $userId, $e::class));
                if (!$this->em->isOpen()) {
                    $this->doctrine->resetManager();
                }
            }
        }

        $io->success(sprintf('%d account(s) purged%s.', $purged, $failed > 0 ? sprintf(', %d FAILED', $failed) : ''));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
