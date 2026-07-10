<?php

declare(strict_types=1);

namespace App\Document\Enum;

/**
 * Why a version exists. Every version is kept for the evidentiary /
 * non-repudiation story (ADR-004): the uploaded original, then one Signed
 * version per signature.
 */
enum DocumentVersionKind: string
{
    case Original = 'original';
    case Signed = 'signed';
}
