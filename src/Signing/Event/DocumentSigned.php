<?php

declare(strict_types=1);

namespace App\Signing\Event;

use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Entity\DocumentVersion;

/**
 * Someone signed a document. $remaining is how many signers the queue still has
 * to reach, and is 0 for a document its owner signed alone.
 *
 * The owner is the audience. Whoever consumes this must skip the case where the
 * signer is the owner: telling someone they did the thing they just did is noise.
 */
final readonly class DocumentSigned
{
    public function __construct(
        public Document $document,
        public User $signer,
        public DocumentVersion $version,
        public int $remaining = 0,
    ) {
    }
}
