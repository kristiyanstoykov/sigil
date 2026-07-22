<?php

declare(strict_types=1);

namespace App\Signing\Exception;

use App\Core\Exception\DomainException;

/**
 * Raised when the PAdES signing operation fails. Carries only a terse,
 * PIN-free message - the underlying driver reports the exception *type* only
 * (see bin/sign_pdf.py), so nothing sensitive can reach a log or the UI.
 */
class SigningException extends DomainException
{
}
