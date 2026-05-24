<?php

declare(strict_types=1);

namespace App\Core\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

trait HasUuid
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    public function getId(): Uuid
    {
        return $this->id;
    }

    #[ORM\PrePersist]
    public function initUuid(): void
    {
        if (!isset($this->id)) {
            $this->id = Uuid::v7();
        }
    }
}
