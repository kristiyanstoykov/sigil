<?php

declare(strict_types=1);

namespace App\Certificate\Service;

use App\Core\Exception\DomainException;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\PasswordStrength;
use Symfony\Component\Validator\Constraints\PasswordStrengthValidator;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What a certificate PIN must look like, spelled once: the forms and
 * CertificateIssuer both ask here, so the browser and the server agree.
 *
 * A PIN opens a software token whose file can be copied and attacked offline
 * (ADR-014), so digits alone are not enough: 8-64 printable characters with an
 * entropy floor, estimated the same way the account password is. Only a NEW
 * PIN is held to this - an existing one is whatever it was when it was set.
 */
final class PinPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 64;
    public const MIN_STRENGTH = PasswordStrength::STRENGTH_WEAK;

    public const HINT = '8-64 characters - mix letters, digits or symbols';

    public static function assert(#[\SensitiveParameter] string $pin): void
    {
        $length = mb_strlen($pin);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new DomainException(sprintf('The PIN must be %d to %d characters.', self::MIN_LENGTH, self::MAX_LENGTH));
        }
        if (!mb_check_encoding($pin, 'UTF-8') || 1 === preg_match('/\p{C}/u', $pin)) {
            throw new DomainException('The PIN contains characters that cannot be typed.');
        }
        if (PasswordStrengthValidator::estimateStrength($pin) < self::MIN_STRENGTH) {
            throw new DomainException('This PIN is too easy to guess - make it longer, or mix letters, digits and symbols.');
        }
    }

    /** The same rule as a form constraint, so the message lands under the field. */
    public static function constraint(): Callback
    {
        return new Callback(static function (?string $pin, ExecutionContextInterface $context): void {
            if (null === $pin || '' === $pin) {
                return; // NotBlank's job
            }
            try {
                self::assert($pin);
            } catch (DomainException $e) {
                $context->buildViolation($e->getMessage())->addViolation();
            }
        });
    }
}
