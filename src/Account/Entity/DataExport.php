<?php

declare(strict_types=1);

namespace App\Account\Entity;

use App\Account\Enum\DataExportStatus;
use App\Account\Repository\DataExportRepository;
use App\Core\Entity\Trait\HasTimestamps;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * One request for "everything I can open, as files" (ADR-015 §7). Built by the
 * worker; its id is the TempDownload group the parts are published under.
 */
#[ORM\Entity(repositoryClass: DataExportRepository::class)]
#[ORM\Table(name: 'data_export')]
#[ORM\Index(name: 'idx_data_export_owner', columns: ['owner_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class DataExport
{
    use HasUuid;
    use HasTimestamps;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 20, enumType: DataExportStatus::class)]
    private DataExportStatus $status = DataExportStatus::Queued;

    #[ORM\Column(options: ['default' => 0])]
    private int $fileCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $partCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(User $owner)
    {
        $this->initUuid();
        $this->owner = $owner;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getStatus(): DataExportStatus
    {
        return $this->status;
    }

    public function getFileCount(): int
    {
        return $this->fileCount;
    }

    public function getPartCount(): int
    {
        return $this->partCount;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function start(): void
    {
        $this->status = DataExportStatus::Building;
    }

    public function finish(int $fileCount, int $partCount, \DateTimeImmutable $at): void
    {
        $this->status = DataExportStatus::Ready;
        $this->fileCount = $fileCount;
        $this->partCount = $partCount;
        $this->finishedAt = $at;
    }

    public function fail(\DateTimeImmutable $at): void
    {
        $this->status = DataExportStatus::Failed;
        $this->finishedAt = $at;
    }
}
