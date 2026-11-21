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
 * 6-64 printable characters, digits alone allowed - a PIN people can remember -
 * but not a repeated block or a straight run (000000, 121212, 123456, 567890).
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
        if (self::isObvious($pin)) {
            throw new DomainException('That PIN is too easy to guess. Avoid repeats and sequences like 123456.');
        }
    }

    /** A block of 1-3 characters repeated (000000, 121212), or a run up or down by one (123456, 987654, 567890, abcdef). */
    private static function isObvious(#[\SensitiveParameter] string $pin): bool
    {
        $chars = mb_str_split(mb_strtolower($pin));
        $length = \count($chars);

        for ($block = 1; $block <= 3; ++$block) {
            $repeats = true;
            for ($i = $block; $i < $length; ++$i) {
                if ($chars[$i] !== $chars[$i % $block]) {
                    $repeats = false;
                    break;
                }
            }
            if ($repeats) {
                return true;
            }
        }

        $direction = null;
        for ($i = 1; $i < $length; ++$i) {
            $step = self::step($chars[$i - 1], $chars[$i]);
            if (null === $step || (null !== $direction && $step !== $direction)) {
                return false;
            }
            $direction = $step;
        }

        return true;
    }

    /** +1 or -1 when $b follows or precedes $a; digits wrap 9 to 0. */
    private static function step(string $a, string $b): ?int
    {
        if (ctype_digit($a) && ctype_digit($b)) {
            return match (((int) $b - (int) $a + 10) % 10) {
                1 => 1,
                9 => -1,
                default => null,
            };
        }
        if (ctype_digit($a) || ctype_digit($b)) {
            return null;
        }
        $diff = mb_ord($b) - mb_ord($a);

        return 1 === abs($diff) ? $diff : null;
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
