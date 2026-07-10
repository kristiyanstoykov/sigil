<?php

declare(strict_types=1);

namespace App\Document\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\Document\Repository\DocumentKeyGrantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Grants one user access to one document version by holding that version's DEK
 * wrapped under the user's KEK (ADR-004). One row per user with access - this
 * is what makes sharing cheap:
 *
 *  - Share  = unwrap the DEK, re-wrap it under the recipient's KEK, insert a
 *             new grant row. The ciphertext file is never touched.
 *  - Revoke = delete that user's grant row.
 *
 * The column holds base64 of a self-describing envelope - never a raw DEK.
 */
#[ORM\Entity(repositoryClass: DocumentKeyGrantRepository::class)]
#[ORM\Table(name: 'document_key_grant')]
#[ORM\UniqueConstraint(name: 'uniq_grant_version_user', columns: ['version_id', 'user_id'])]
#[ORM\HasLifecycleCallbacks]
class DocumentKeyGrant
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
    #[ORM\JoinColumn(name: 'version_id', nullable: false)]
    private DocumentVersion $version;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private User $user;

    /** base64 of the KEK-wrapped DEK envelope. Never a raw key. */
    #[ORM\Column(type: 'text')]
    private string $wrappedDek;

    public function __construct(DocumentVersion $version, User $user, string $wrappedDek)
    {
        $this->initUuid();
        $this->version = $version;
        $this->user = $user;
        $this->wrappedDek = $wrappedDek;
    }

    public function getVersion(): DocumentVersion
    {
        return $this->version;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getWrappedDek(): string
    {
        return $this->wrappedDek;
    }
}
