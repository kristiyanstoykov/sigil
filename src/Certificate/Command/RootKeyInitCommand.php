<?php

declare(strict_types=1);

namespace App\Certificate\Command;

use App\Certificate\Service\Pkcs11TokenManager;
use App\Core\Crypto\Pkcs11RootKeyWrapper;
use App\Core\Exception\DomainException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provisions the ADR-010 root wrapping key: a PKCS#11 token holding a
 * non-exportable AES-256 key that wraps every per-user KEK. Idempotent - safe
 * to re-run (e.g. after `docker compose down` wipes an anonymous volume, the
 * same way `sigil:ca:init` repairs the CA). The key is generated inside the
 * token and never leaves it.
 */
#[AsCommand(
    name: 'sigil:root-key:init',
    description: 'Initialize the ADR-010 root wrapping key: PKCS#11 token + non-exportable in-token AES key',
)]
final class RootKeyInitCommand extends Command
{
    public function __construct(
        private readonly Pkcs11TokenManager $tokens,
        private readonly Pkcs11RootKeyWrapper $rootWrapper,
        #[Autowire(env: 'SIGIL_ROOT_TOKEN_LABEL')]
        private readonly string $tokenLabel,
        #[Autowire(env: 'SIGIL_ROOT_TOKEN_PIN')]
        private readonly string $pin,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            if ($this->tokens->tokenExists($this->tokenLabel)) {
                $io->note(sprintf('PKCS#11 token "%s" already exists; reusing it.', $this->tokenLabel));
            } else {
                $this->tokens->initToken($this->tokenLabel, $this->pin);
                $io->text(sprintf('Initialized PKCS#11 token "%s".', $this->tokenLabel));
            }

            $created = $this->rootWrapper->provisionKey();
        } catch (DomainException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success($created
            ? 'Root wrapping key created inside the token (non-exportable — never leaves it). '
                .'Run sigil:root-key:migrate to move existing KEKs onto it.'
            : 'Root wrapping key already present; nothing to do.');

        return Command::SUCCESS;
    }
}
