<?php

declare(strict_types=1);

namespace App\Account\Command;

use App\Account\Service\ArchiveKeyWrapper;
use App\Certificate\Service\Pkcs11TokenManager;
use App\Core\Exception\DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provisions the ADR-015 archive key: a `sigil-archive` PKCS#11 token holding
 * the non-exportable AES key that deleted users' KEKs are re-wrapped under.
 * Idempotent, so the bootstrap runs it on every start and it repairs a token
 * whose key is missing. Losing the token makes every archived account unrestorable.
 */
#[AsCommand(
    name: 'sigil:archive:init',
    description: 'Initialize the ADR-015 archive key: PKCS#11 token + non-exportable in-token AES key',
)]
final class ArchiveInitCommand extends Command
{
    public function __construct(
        private readonly Pkcs11TokenManager $tokens,
        private readonly ArchiveKeyWrapper $archive,
        #[Autowire(env: 'SIGIL_ARCHIVE_PIN')]
        private readonly string $pin,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $label = $this->archive->tokenLabel();

        try {
            if ($this->tokens->tokenExists($label)) {
                $io->note(sprintf('PKCS#11 token "%s" already exists; reusing it.', $label));
            } else {
                $this->tokens->initToken($label, $this->pin);
                $io->text(sprintf('Initialized PKCS#11 token "%s".', $label));
            }

            $created = $this->archive->provisionKey();
        } catch (DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($created
            ? 'Archive key created inside the token (non-exportable - never leaves it). Back the token up with the others.'
            : 'Archive key already present; nothing to do.');

        return Command::SUCCESS;
    }
}
