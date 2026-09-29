<?php

declare(strict_types=1);

namespace App\Certificate\Service;

use App\Core\Exception\DomainException;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What a certificate PIN must look like, spelled once: the forms and
 * CertificateIssuer both ask here, so the browser and the server agree.
 *
 * 6-64 printable characters, digits alone allowed - a PIN people can remember.
 * The cost is stated in ADR-014: a copied token file can be searched offline,
 * so a short numeric PIN only holds while the token volume stays on the host.
 */
final class PinPolicy
{
    public const MIN_LENGTH = 6;
    public const MAX_LENGTH = 64;

    public const HINT = '6-64 characters - digits are fine';

    public static function assert(#[\SensitiveParameter] string $pin): void
    {
        $length = mb_strlen($pin);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new DomainException(sprintf('The PIN must be %d to %d characters.', self::MIN_LENGTH, self::MAX_LENGTH));
        }
        if (!mb_check_encoding($pin, 'UTF-8') || 1 === preg_match('/\p{C}/u', $pin)) {
            throw new DomainException('The PIN contains characters that cannot be typed.');
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
