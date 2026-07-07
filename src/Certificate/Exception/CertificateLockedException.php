<?php

declare(strict_types=1);

namespace App\Certificate\Exception;

use App\Core\Exception\DomainException;

final class CertificateLockedException extends DomainException {}
