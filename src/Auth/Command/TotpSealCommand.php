<?php

declare(strict_types=1);

namespace App\Auth\Command;

use App\Auth\Security\TotpSecretVault;
use App\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seal the TOTP seeds of users enrolled before 2026-09-21, when the column
 * held the base32 seed in the clear. Idempotent: sealed rows are skipped.
 */
#[AsCommand(name: 'sigil:totp:seal', description: 'Encrypt any TOTP seeds still stored in the clear (pre-2026-09-21 rows); idempotent')]
final class TotpSealCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TotpSecretVault $vault,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sealed = 0;

        foreach ($this->users->findAll() as $user) {
            $stored = $user->getGoogleAuthenticatorSecret();
            if (null === $stored || TotpSecretVault::isSealed($stored)) {
                continue;
            }
            $user->setGoogleAuthenticatorSecret($this->vault->seal($stored, $user->getId()->toRfc4122()));
            ++$sealed;
        }
        $this->em->flush();

        $io->success(0 === $sealed ? 'Every TOTP seed is already sealed.' : sprintf('Sealed %d TOTP seed(s).', $sealed));

        return Command::SUCCESS;
    }
}
