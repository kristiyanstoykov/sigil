<?php

declare(strict_types=1);

namespace App\Signing\Command;

use App\Document\Service\DocumentEraser;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\SigningRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Closes signature requests whose deadline has passed. Meant for cron - once an
 * hour is plenty, since the deadline is measured in days.
 *
 * A request nobody signed takes its document with it: Sigil is not a storage
 * application, and an unsigned document that ran out of time has no reason to
 * exist. One signature is enough to keep the document forever - signed artifacts
 * have evidentiary value - and the request is just marked Expired.
 */
#[AsCommand(
    name: 'sigil:signing:sweep',
    description: 'Expire overdue signature requests, deleting the ones nobody signed',
)]
final class SweepSigningRequestsCommand extends Command
{
    public function __construct(
        private readonly SigningRequestRepository $requests,
        private readonly SigningRequestService $service,
        private readonly DocumentEraser $eraser,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would happen without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $overdue = $this->requests->findOverdue($now);
        if ([] === $overdue) {
            $io->success('No overdue signature requests.');

            return Command::SUCCESS;
        }

        $expired = 0;
        $erased = 0;

        foreach ($overdue as $request) {
            $document = $request->getDocument();
            $title = $document->getTitle();
            $signed = $request->hasAnySignature();

            if ($dryRun) {
                $io->writeln(sprintf('%s %s', $signed ? 'expire' : 'DELETE', $title));
                continue;
            }

            // Expire first either way: the request must be closed on the record
            // before its document disappears, and closing also takes the pending
            // signer's access away.
            $this->service->expire($request);

            if ($signed) {
                ++$expired;
                continue;
            }

            // The request goes first, through the ORM. Its row would vanish with
            // the document anyway (the FK is onDelete: CASCADE), but a cascade
            // the database performs is one Doctrine never sees: the request and
            // its signers would stay managed over deleted rows and abort the
            // next request's flush, stranding the rest of the run.
            $requester = $request->getRequester();
            $this->em->remove($request);
            $this->em->flush();

            $this->eraser->erase($document, $requester, 'signing request expired unsigned');

            ++$erased;
        }

        if ($dryRun) {
            $io->note(sprintf('%d overdue request(s). Nothing was changed.', \count($overdue)));

            return Command::SUCCESS;
        }

        $this->em->flush();
        $io->success(sprintf('%d request(s) expired, %d unsigned document(s) deleted.', $expired, $erased));

        return Command::SUCCESS;
    }
}
