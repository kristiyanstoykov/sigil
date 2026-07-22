<?php

declare(strict_types=1);

namespace App\Signing\Service;

use App\Signing\Exception\SigningException;

/**
 * Strategy seam for producing a PAdES signature (ADR-007). The MVP impl is
 * {@see PyHankoAdapter} (native PKCS#11 via bin/sign_pdf.py); a SetaPDF or CSC
 * backend can replace it without touching the signing flow.
 */
interface PadesSignerInterface
{
    /**
     * Produce a PAdES-signed copy of the request's PDF - signed by the
     * token-held key and carrying the Sigil visible stamp. The PIN only opens
     * the PKCS#11 session and is never stored, queued or logged.
     *
     * @return string the signed PDF bytes
     *
     * @throws SigningException on any signing failure (terse, PIN-free message)
     */
    public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string;
}
