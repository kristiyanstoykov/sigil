<?php

declare(strict_types=1);

namespace App\Signing\Service;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One RFC-3161 timestamping backend. Which one is *active* is a deployment
 * setting (`SIGIL_TSA_ACTIVE_BACKEND`), not a user choice - mirroring the
 * pluggable object-storage backends (ADR-009). Adding a TSA = adding a tagged
 * service; switching only changes which URL new signatures are timestamped by.
 */
#[AutoconfigureTag('app.tsa_provider')]
interface TsaProviderInterface
{
    /** Stable id selected by SIGIL_TSA_ACTIVE_BACKEND, e.g. "freetsa", "none". */
    public function id(): string;

    /** RFC-3161 TSA endpoint to timestamp with, or null for none (PAdES-B-B). */
    public function url(): ?string;
}
