<?php

declare(strict_types=1);

namespace App\Document\Command;

use App\Core\Crypto\EnvRootKeyWrapper;
use App\Core\Crypto\Exception\DecryptionFailedException;
use App\Core\Crypto\Pkcs11RootKeyWrapper;
use App\Core\Exception\DomainException;
use App\Document\Repository\UserEncryptionKeyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off migration for ADR-010: re-wrap every user KEK from the legacy env
 * root key into the PKCS#11 token. Only the wrapping changes - the KEK bytes
 * are identical, so every DocumentKeyGrant (DEK) stays valid and no ciphertext
 * is touched.
 *
 * Idempotent: token-wrapped rows carry a 0x02 scheme byte and are skipped, so
 * the command can be re-run safely (e.g. after adding users mid-migration).
 * Requires SIGIL_ROOT_KEY (source) and a provisioned token (`sigil:root-key:init`).
 */
#[AsCommand(
    name: 'sigil:root-key:migrate',
    description: 'Re-wrap every user KEK from the env root key into the PKCS#11 token (ADR-010)',
)]
final class RootKeyMigrateCommand extends Command
{
    /** Scheme byte marking a KEK already wrapped by the token wrapper. */
    private const TOKEN_SCHEME_BYTE = "\x02";

    public function __construct(
        private readonly EnvRootKeyWrapper $envWrapper,
        private readonly Pkcs11RootKeyWrapper $tokenWrapper,
        private readonly UserEncryptionKeyRepository $keys,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $migrated = 0;
        $skipped = 0;

        foreach ($this->keys->findAll() as $row) {
            $blob = base64_decode($row->getWrappedKek(), true);
            if (false === $blob || '' === $blob) {
                $io->error(sprintf('Malformed wrapped KEK for user %s; aborting.', $row->getUser()->getId()->toRfc4122()));

                return Command::FAILURE;
            }

            if (self::TOKEN_SCHEME_BYTE === $blob[0]) {
                ++$skipped;
                continue;
            }

            // Must match KeyManagementService::kekAad() exactly, or the unwrap fails.
            $aad = 'kek:'.$row->getUser()->getId()->toRfc4122();

            try {
                $kek = $this->envWrapper->unwrapKek($blob, $aad);
                try {
                    $rewrapped = base64_encode($this->tokenWrapper->wrapKek($kek, $aad));
                } finally {
                    sodium_memzero($kek);
                }
            } catch (DecryptionFailedException | DomainException $e) {
                $io->error(sprintf('Re-wrap failed for user %s (%s); aborting before any DB change to that row.', $row->getUser()->getId()->toRfc4122(), $e::class));

                return Command::FAILURE;
            }

            $this->keys->updateWrappedKek($row, $rewrapped);
            ++$migrated;
        }

        $io->success(sprintf('Root-key migration complete: %d migrated, %d already on the token.', $migrated, $skipped));

        return Command::SUCCESS;
    }
}
