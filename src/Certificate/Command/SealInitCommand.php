<?php

declare(strict_types=1);

namespace App\Certificate\Command;

use App\Certificate\Service\CertificateIssuer;
use App\Core\Exception\DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sigil:seal:init',
    description: 'Initialize the Sigil delivery seal: PKCS#11 token, in-token keypair, CA-issued seal certificate',
)]
final class SealInitCommand extends Command
{
    public function __construct(private readonly CertificateIssuer $issuer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        // For the bootstrap: an initialised Seal is success, not a refusal.
        $this->addOption('if-missing', null, InputOption::VALUE_NONE, 'Do nothing (successfully) when already initialized');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('if-missing') && $this->issuer->hasSeal()) {
            $io->note('Seal already initialized; nothing to do.');

            return Command::SUCCESS;
        }

        try {
            $path = $this->issuer->bootstrapSeal();
        } catch (DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Seal initialized. Certificate written to %s (valid %d days).', $path, CertificateIssuer::SEAL_CERT_DAYS));
        $io->note('The seal signs delivery receipts on the application\'s behalf. Unlike a user certificate its PIN lives in SIGIL_SEAL_PIN, so the server can seal unattended - see ADR-012.');

        return Command::SUCCESS;
    }
}
