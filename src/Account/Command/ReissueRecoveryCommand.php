<?php

declare(strict_types=1);

namespace App\Account\Command;

use App\Account\Repository\ArchivedUserKeyRepository;
use App\Account\Service\RecoveryCode;
use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Auth\Security\EmailFingerprinter;
use App\Certificate\Service\PinHasher;
use App\Core\Repository\UserRepository;
use App\Mailer\Service\Mailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Mails a deleted account a new recovery code, after the administrator has
 * checked who is asking out of band (ADR-015 §4). Console only, like
 * sigil:admin:grant, and the code is never printed.
 */
#[AsCommand(name: 'sigil:account:reissue-recovery', description: 'Mail a deleted account a new recovery code (after an out-of-band identity check)')]
final class ReissueRecoveryCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ArchivedUserKeyRepository $archivedKeys,
        private readonly EmailFingerprinter $fingerprinter,
        private readonly PinHasher $hasher,
        private readonly Mailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly AuditLoggerInterface $audit,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'The address the deleted account had');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var string $email */
        $email = $input->getArgument('email');

        $user = $this->users->findDeletedByEmailFingerprint($this->fingerprinter->fingerprint($email));
        if (null === $user) {
            $io->error(sprintf('No deleted account had the address %s.', $email));

            return Command::FAILURE;
        }

        $code = RecoveryCode::generate();

        // Mailed before it is stored: a code nobody received must not replace one they hold.
        try {
            $this->mailer->send(
                (new TemplatedEmail())
                    ->to($email)
                    ->subject('Your new Sigil recovery code')
                    ->htmlTemplate('emails/account_recovery_reissued.html.twig')
                    ->context([
                        'recoveryCode' => $code,
                        'retainUntil' => $this->archivedKeys->findForUser($user)?->getRetainUntil(),
                    ]),
            );
        } catch (TransportExceptionInterface $e) {
            $io->error(sprintf('The mail could not be sent (%s). The old code still stands.', $e->getMessage()));

            return Command::FAILURE;
        }

        $this->em->wrapInTransaction(function () use ($user, $code): void {
            $user->replaceRecoveryCodeHash($this->hasher->hash(RecoveryCode::normalize($code)));
            $this->em->flush();
            $this->audit->log(
                action: 'account.recovery_reissued',
                subjectType: 'User',
                subjectId: $user->getId()->toRfc4122(),
                severity: AuditSeverity::Warning,
            );
        });

        $io->success(sprintf('A new recovery code was mailed to %s. The old one no longer works.', $email));

        return Command::SUCCESS;
    }
}
