<?php

declare(strict_types=1);

namespace App\Mailer\Command;

use App\Mailer\Service\Mailer;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

/**
 * Sends every email template with sample content to one address, through the
 * configured transport - the only way to see them in a real mail client.
 */
#[AsCommand(name: 'sigil:mail:preview', description: 'Send every email template with sample content to an address')]
final class PreviewEmailsCommand extends Command
{
    public function __construct(private readonly Mailer $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::REQUIRED, 'Recipient address')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Send just this template (key from the list)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $to = (string) $input->getArgument('to');
        $only = $input->getOption('only');

        $sent = 0;
        foreach ($this->samples() as $key => [$subject, $template, $context]) {
            if (null !== $only && $only !== $key) {
                continue;
            }

            $this->mailer->send(
                (new TemplatedEmail())
                    ->to($to)
                    ->subject(sprintf('[preview: %s] %s', $key, $subject))
                    ->htmlTemplate($template)
                    ->context($context),
            );
            $io->writeln(sprintf('  sent  <info>%s</info>', $key));
            ++$sent;
        }

        if (0 === $sent) {
            $io->error(sprintf('No template named "%s". Known: %s', $only, implode(', ', array_keys($this->samples()))));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d email(s) sent to %s.', $sent, $to));

        return Command::SUCCESS;
    }

    /** @return array<string, array{string, string, array<string, mixed>}> */
    private function samples(): array
    {
        $title = 'Договор за наем - Кристиян Стойков.pdf';
        $show = 'http://localhost:8000/documents/01a0a6c0-64a9-7e2a-b6db-4f8d295ef9b7';
        $deadline = new \DateTimeImmutable('+7 days');

        return [
            'verify' => ['Confirm your email address', 'auth/email/confirmation_email.html.twig', [
                'signedUrl' => 'http://localhost:8000/verify/email?expires=1&signature=abc%2Fdef&token=xyz',
                'expiresAtMessageKey' => '%count% hour|%count% hours',
                'expiresAtMessageData' => ['%count%' => 1],
            ]],
            'reset' => ['Reset your password', 'auth/reset_password/email.html.twig', [
                'resetToken' => new ResetPasswordToken('sample-token', new \DateTimeImmutable('+1 hour'), time()),
            ]],
            'uploaded' => ['Your document is stored', 'emails/document_uploaded.html.twig', ['title' => $title, 'url' => $show]],
            'shared' => ['A document was shared with you', 'emails/document_shared.html.twig', ['title' => $title, 'sharedBy' => 'Мария Петрова', 'url' => $show]],
            'turn' => ['Your signature is requested', 'emails/signing_turn.html.twig', [
                'title' => $title, 'requester' => 'Мария Петрова', 'position' => 2, 'total' => 3, 'deadline' => $deadline, 'url' => $show.'/sign',
            ]],
            'signed' => ['A signer has signed', 'emails/document_signed.html.twig', ['title' => $title, 'signedBy' => 'Георги Иванов', 'remaining' => 1, 'url' => $show]],
            'completed' => ['Everyone has signed', 'emails/signing_completed.html.twig', ['title' => $title, 'total' => 3, 'url' => $show]],
            'declined' => ['A signature request was declined', 'emails/signing_closed.html.twig', [
                'title' => $title, 'status' => 'declined', 'expired' => false, 'requester' => 'Мария Петрова', 'deadline' => $deadline,
                'signed' => 1, 'declinedBy' => 'Георги Иванов', 'declineReason' => 'Wrong counterparty named in clause 4.',
            ]],
            'expired' => ['A signature request expired', 'emails/signing_closed.html.twig', [
                'title' => $title, 'status' => 'expired', 'expired' => true, 'requester' => 'Мария Петрова', 'deadline' => new \DateTimeImmutable('-1 hour'),
                'signed' => 0, 'declinedBy' => null, 'declineReason' => null,
            ]],
            'delivered' => ['A document has been delivered to you', 'emails/document_delivered.html.twig', [
                'title' => $title, 'sender' => 'Мария Петрова', 'note' => 'Signed copy for your records.', 'url' => $show,
            ]],
        ];
    }
}
