<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use App\Core\Crypto\Exception\DecryptionFailedException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Looks up AEAD suites by their stable id. The default for new encryptions is
 * fixed here (ADR-006) - a deployment decision, not a user choice. Decryption
 * selects the suite named in the envelope, so old artifacts keep working after
 * the default advances (crypto agility).
 */
final class CipherAlgorithmRegistry
{
    /** @var array<string, CipherAlgorithmInterface> */
    private array $ciphers = [];

    /**
     * @param iterable<CipherAlgorithmInterface> $ciphers
     */
    public function __construct(
        #[AutowireIterator('app.cipher_algorithm')]
        iterable $ciphers,
    ) {
        foreach ($ciphers as $cipher) {
            $this->ciphers[$cipher->id()] = $cipher;
        }
    }

    /**
     * Resolve the suite named in an envelope. Unknown id is treated as a
     * decryption failure (generic), not a distinct error - never reveal
     * whether the id was recognised.
     *
     * @throws DecryptionFailedException
     */
    public function get(string $id): CipherAlgorithmInterface
    {
        return $this->ciphers[$id] ?? throw new DecryptionFailedException();
    }

    /** The suite used for all new encryptions. */
    public function default(): CipherAlgorithmInterface
    {
        return $this->get(AesGcmSodiumCipher::ID);
    }
}
