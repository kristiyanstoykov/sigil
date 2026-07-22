<?php

declare(strict_types=1);

namespace App\Signing\Exception;

/**
 * The PKCS#11 token rejected the PIN even though the Argon2id hash gate had
 * already accepted it (ADR-008 desync tripwire). The signing flow reacts by
 * locking the certificate via {@see \App\Certificate\Service\PinGate::reportTokenPinRejected()}.
 */
final class TokenPinRejectedException extends SigningException
{
}
