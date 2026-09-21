<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use App\Core\Crypto\Exception\DecryptionFailedException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Looks up AEAD suites by their stable id. The *active* suite - the one new
 * encryptions use - is a deployment setting (SIGIL_CIPHER_ACTIVE_ALGORITHM,
 * ADR-006), not a user choice. Decryption selects the suite named in the
 * envelope, so old artifacts keep working after the active one advances -
 * the same shape as the storage backends (ADR-009) and signature suites (ADR-014).
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
        #[Autowire('%env(SIGIL_CIPHER_ACTIVE_ALGORITHM)%')]
        private readonly string $activeId = AesGcmSodiumCipher::ID,
    ) {
        foreach ($ciphers as $cipher) {
            $this->ciphers[$cipher->id()] = $cipher;
        }

        // A misconfigured active cipher is a boot error, not a decryption failure.
        if (!isset($this->ciphers[$this->activeId])) {
            throw new \InvalidArgumentException(sprintf('Unknown active cipher "%s".', $this->activeId));
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
    public function active(): CipherAlgorithmInterface
    {
        return $this->ciphers[$this->activeId];
    }
}
