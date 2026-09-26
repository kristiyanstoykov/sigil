<?php

declare(strict_types=1);

namespace App\Auth\Command;

use App\Auth\Service\RegistrationAllowlist;
use App\Core\Exception\DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Put an address on the registration allowlist from the console - how the first
 * admin gets in on a fresh deployment, before anyone can reach the admin page.
 */
#[AsCommand(name: 'sigil:allowlist:add', description: 'Allow an email address to register (idempotent)')]
final class AllowlistAddCommand extends Command
{
    public function __construct(private readonly RegistrationAllowlist $allowlist)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The address to allow');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $email */
        $email = $input->getArgument('email');

        if (false === filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
            $io->error('That is not an email address.');

            return Command::INVALID;
        }

        try {
            $entry = $this->allowlist->add($email);
            $io->success(sprintf('%s can now register.', $entry->getEmail()));
        } catch (DomainException) {
            $io->note(sprintf('%s is already on the allowlist.', mb_strtolower(trim($email))));
        }

        return Command::SUCCESS;
    }
}
