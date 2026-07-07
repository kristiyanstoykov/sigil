<?php

declare(strict_types=1);

namespace App\Certificate\Command;

use App\Certificate\Service\CertificateIssuer;
use App\Core\Exception\DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sigil:ca:init',
    description: 'Initialize the Sigil CA: PKCS#11 token, in-token keypair, self-signed CA certificate',
)]
final class CaInitCommand extends Command
{
    public function __construct(private readonly CertificateIssuer $issuer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $path = $this->issuer->bootstrapCa();
        } catch (DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('CA initialized. Certificate written to %s (valid %d days). The CA key lives in the PKCS#11 token and was never exported.', $path, CertificateIssuer::CA_CERT_DAYS));

        return Command::SUCCESS;
    }
}
