<?php

declare(strict_types=1);

namespace App\Document\Entity;

use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use App\Document\Enum\DocumentDisplayStatus;
use App\Document\Enum\DocumentVersionKind;
use App\Document\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * A logical document owned by a user. It carries no bytes itself - the content
 * lives in its {@see DocumentVersion}s (the uploaded original plus one signed
 * version per signature). All versions are kept (ADR-004).
 */
#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Table(name: 'document')]
#[ORM\HasLifecycleCallbacks]
class Document
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $owner;

    /** Display name as uploaded. Never used as a filesystem path. */
    #[ORM\Column(length: 255)]
    private string $title;

    /**
     * @var Collection<int, DocumentVersion>
     */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DocumentVersion::class, cascade: ['persist'])]
    #[ORM\OrderBy(['versionNumber' => 'ASC'])]
    private Collection $versions;

    public function __construct(User $owner, string $title)
    {
        $this->initUuid();
        $this->owner = $owner;
        $this->title = $title;
        $this->versions = new ArrayCollection();
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @return Collection<int, DocumentVersion>
     */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function addVersion(DocumentVersion $version): void
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
        }
    }

    public function getLatestVersion(): ?DocumentVersion
    {
        return $this->versions->last() ?: null;
    }

    /**
     * Whether this document already carries a Sigil signature.
     *
     * Deliberately scoped to versions *we* minted: a PDF that arrived already
     * signed elsewhere (Borica, Evrotrust, ...) is still signable here, and
     * multi-party counter-signing - a separate future feature - will add its
     * own notion of "who still has to sign" without touching this one.
     */
    public function isSigned(): bool
    {
        foreach ($this->versions as $version) {
            if (DocumentVersionKind::Signed === $version->getKind()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The document's lifecycle state for the UI. Derived, never stored - the
     * versions already hold the truth.
     */
    public function getDisplayStatus(): DocumentDisplayStatus
    {
        return $this->isSigned() ? DocumentDisplayStatus::Signed : DocumentDisplayStatus::Draft;
    }

    /** Next 1-based version number for a new version of this document. */
    public function nextVersionNumber(): int
    {
        $latest = $this->getLatestVersion();

        return null === $latest ? 1 : $latest->getVersionNumber() + 1;
    }
}
