<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificate;

use App\Certificate\Service\PinPolicy;
use App\Core\Exception\DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PinPolicyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptable(): iterable
    {
        yield 'six digits' => ['135790'];
        yield 'long digits' => ['1357924680135792'];
        yield 'digits with one break' => ['123457'];
        yield 'letters do not wrap' => ['xyzabc'];
        yield 'block longer than three' => ['12341234'];
        yield 'mixed ascii' => ['Sigil-2026!'];
        yield 'passphrase with spaces' => ['correct horse battery 7'];
        yield 'cyrillic' => ['Стойков-2026'];
        yield 'at the maximum' => [str_repeat('Ab3!', 16)];
    }

    #[DataProvider('acceptable')]
    public function testAcceptsAPinOfTheRightShape(string $pin): void
    {
        $this->expectNotToPerformAssertions();
        PinPolicy::assert($pin);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejected(): iterable
    {
        yield 'five digits' => ['13579', 'must be 6 to 64 characters'];
        yield 'over the maximum' => [str_repeat('Ab3!', 17), 'must be 6 to 64 characters'];
        yield 'control character' => ["Sigil-2026\t!", 'cannot be typed'];
        yield 'invalid utf-8' => ["Sigil-2026\xff!", 'cannot be typed'];
        yield 'one digit repeated' => ['000000', 'too easy to guess'];
        yield 'one letter repeated' => ['aaaaaaa', 'too easy to guess'];
        yield 'pair repeated' => ['1212121', 'too easy to guess'];
        yield 'triple repeated' => ['123123', 'too easy to guess'];
        yield 'letter block repeated, any case' => ['abcABC', 'too easy to guess'];
        yield 'ascending' => ['123456', 'too easy to guess'];
        yield 'descending' => ['987654', 'too easy to guess'];
        yield 'wraps past nine' => ['567890', 'too easy to guess'];
        yield 'wraps descending' => ['321098', 'too easy to guess'];
        yield 'full keypad row' => ['1234567890', 'too easy to guess'];
        yield 'ascending letters, any case' => ['abCDef', 'too easy to guess'];
    }

    #[DataProvider('rejected')]
    public function testRejectsAWeakOrMalformedPin(string $pin, string $message): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);
        PinPolicy::assert($pin);
    }
}
