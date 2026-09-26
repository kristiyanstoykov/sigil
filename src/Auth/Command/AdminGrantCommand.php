<?php

declare(strict_types=1);

namespace App\Auth\Command;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Auth\Service\RegistrationAllowlist;
use App\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Make an existing account an administrator. Console only on purpose: there is
 * no page that hands out ROLE_ADMIN, so a compromised admin session cannot mint
 * another one.
 */
#[AsCommand(name: 'sigil:admin:grant', description: 'Give an existing account ROLE_ADMIN (idempotent)')]
final class AdminGrantCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RegistrationAllowlist $allowlist,
        private readonly EntityManagerInterface $em,
        private readonly AuditLoggerInterface $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The account to promote');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $email */
        $email = $input->getArgument('email');

        $user = $this->users->findOneByEmail($email);
        if (null === $user) {
            $io->error(sprintf('No account for %s. Allow it with sigil:allowlist:add, register, then run this again.', $email));

            return Command::FAILURE;
        }

        if (!$this->allowlist->permits($user->getEmail())) {
            $this->allowlist->add($user->getEmail());
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            $io->note(sprintf('%s is already an administrator.', $user->getEmail()));

            return Command::SUCCESS;
        }

        $this->em->wrapInTransaction(function () use ($user): void {
            $roles = array_values(array_diff($user->getRoles(), ['ROLE_USER']));
            $roles[] = 'ROLE_ADMIN';
            $user->setRoles($roles);
            $this->em->flush();
            $this->audit->log(
                action: 'admin.granted',
                payload: ['email' => $user->getEmail()],
                subjectType: 'User',
                subjectId: $user->getId()->toRfc4122(),
                severity: AuditSeverity::Warning,
            );
        });

        $io->success(sprintf('%s is now an administrator. They must log in again for it to take effect.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
