<?php

declare(strict_types=1);

namespace App\AuditLog\Enum;

enum AuditSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
}
