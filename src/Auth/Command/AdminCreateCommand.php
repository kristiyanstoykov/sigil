<?php

declare(strict_types=1);

namespace App\Auth\Command;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Auth\Service\RegistrationAllowlist;
use App\Core\Entity\User;
use App\Core\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Seed the first administrator on an empty database: a verified account with
 * ROLE_ADMIN, on the allowlist. The password is prompted for, never taken from
 * argv (shell history, `ps`). TOTP enrolment still happens at first login.
 */
#[AsCommand(name: 'sigil:admin:create', description: 'Create a verified administrator account (prompts for name and password)')]
final class AdminCreateCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RegistrationAllowlist $allowlist,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $em,
        private readonly AuditLoggerInterface $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The administrator\'s email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $email */
        $email = $input->getArgument('email');
        $email = mb_strtolower(trim($email));

        if ('' === $email || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('That is not an email address.');

            return Command::INVALID;
        }
        if (null !== $this->users->findOneByEmail($email)) {
            $io->error(sprintf('%s already has an account. Use sigil:admin:grant to promote it.', $email));

            return Command::FAILURE;
        }
        if (!$input->isInteractive()) {
            $io->error('Run this in an interactive terminal (docker compose exec, not -T): the password is prompted for.');

            return Command::FAILURE;
        }

        $firstName = $this->askName($io, 'First name');
        $lastName = $this->askName($io, 'Last name');
        $password = $this->askPassword($io);

        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRoles(['ROLE_SIGNER', 'ROLE_ADMIN'])
            ->setIsVerified(true);
        $user->setPassword($this->hasher->hashPassword($user, $password));

        if (!$this->allowlist->permits($email)) {
            $this->allowlist->add($email);
        }

        $this->em->wrapInTransaction(function () use ($user): void {
            $this->em->persist($user);
            $this->em->flush();
            $this->audit->log(
                action: 'admin.granted',
                payload: ['email' => $user->getEmail(), 'created' => true],
                subjectType: 'User',
                subjectId: $user->getId()->toRfc4122(),
                severity: AuditSeverity::Warning,
            );
        });

        $io->success(sprintf('%s is an administrator. Log in - two-factor setup comes first.', $email));

        return Command::SUCCESS;
    }

    private function askName(SymfonyStyle $io, string $label): string
    {
        /** @var string */
        return $io->ask($label, null, function (?string $value): string {
            $value = trim((string) $value);
            if ('' === $value || mb_strlen($value) > 100) {
                throw new \RuntimeException('Required, at most 100 characters.');
            }

            return $value;
        });
    }

    /** Same rules as the registration form. */
    private function askPassword(SymfonyStyle $io): string
    {
        $constraints = [
            new NotBlank(),
            new Length(min: 10, max: 4096),
            new PasswordStrength(minScore: PasswordStrength::STRENGTH_MEDIUM, message: 'This password is too easy to guess - make it longer or less predictable.'),
        ];

        while (true) {
            $password = (string) $io->askHidden('Password (at least 10 characters)');
            $violations = $this->validator->validate($password, $constraints);
            if (\count($violations) > 0) {
                $io->error((string) $violations->get(0)->getMessage());

                continue;
            }
            if ($password !== (string) $io->askHidden('Repeat the password')) {
                $io->error('The passwords do not match.');

                continue;
            }

            return $password;
        }
    }
}
