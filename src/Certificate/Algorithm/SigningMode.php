<?php

declare(strict_types=1);

namespace App\Certificate\Algorithm;

/**
 * How the token is handed what it signs. The two are not interchangeable: an
 * ML-DSA key fed a pre-computed digest produces a signature over the wrong
 * bytes (RFC 9882 signs the DER of the signed attributes in full).
 */
enum SigningMode: string
{
    /** Hash here, hand the token the digest - raw CKM_ECDSA. */
    case Prehash = 'prehash';

    /** Hand the token the whole message - CKM_ML_DSA. */
    case Pure = 'pure';
}
