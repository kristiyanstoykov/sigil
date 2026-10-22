<?php

declare(strict_types=1);

namespace App\Auth\Security;

use App\Core\Crypto\EncryptionServiceInterface;
use App\Core\Crypto\RootKeyProvider;

/**
 * The only form in which a deleted account keeps its address (ADR-015 §1): a
 * keyed MAC of the normalised address, self-describing like a content
 * fingerprint, so registration can recognise the person without storing who they are.
 */
final readonly class EmailFingerprinter
{
    public const string ALGORITHM = 'email-fingerprint/v1';

    private const string MAC_CONTEXT = 'sigil:email-fingerprint/v1';

    public function __construct(
        private EncryptionServiceInterface $encryption,
        private RootKeyProvider $rootKeys,
    ) {
    }

    public function fingerprint(string $email): string
    {
        $macKey = $this->encryption->deriveKey($this->rootKeys->rootKey(), self::MAC_CONTEXT);
        try {
            return self::ALGORITHM.':'.bin2hex($this->encryption->mac(mb_strtolower(trim($email)), $macKey));
        } finally {
            sodium_memzero($macKey);
        }
    }
}
