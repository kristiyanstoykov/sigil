<?php

declare(strict_types=1);

namespace App\Certificate\Enum;

enum CertificateStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
