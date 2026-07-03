<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Repository\ResetPasswordRequestRepository;
use App\Core\Entity\Trait\HasUuid;
use App\Core\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait;

#[ORM\Entity(repositoryClass: ResetPasswordRequestRepository::class)]
#[ORM\Table(name: 'reset_password_request')]
#[ORM\HasLifecycleCallbacks]
class ResetPasswordRequest implements ResetPasswordRequestInterface
{
    use HasUuid;
    use ResetPasswordRequestTrait;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    public function __construct(
        User $user,
        \DateTimeInterface $expiresAt,
        string $selector,
        string $hashedToken,
    ) {
        $this->user = $user;
        $this->initialize($expiresAt, $selector, $hashedToken);
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
