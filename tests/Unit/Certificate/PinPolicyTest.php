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
        yield 'mixed ascii' => ['Sigil-2026!'];
        yield 'passphrase with spaces' => ['correct horse battery 7'];
        yield 'cyrillic' => ['Стойков-2026'];
        yield 'at the maximum' => [str_repeat('Ab3!', 16)];
    }

    #[DataProvider('acceptable')]
    public function testAcceptsAPinWithEnoughEntropy(string $pin): void
    {
        $this->expectNotToPerformAssertions();
        PinPolicy::assert($pin);
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejected(): iterable
    {
        yield 'the old phone-style pin' => ['123456', 'must be 8 to 64 characters'];
        yield 'eight digits' => ['13579024', 'too easy to guess'];
        yield 'long but digits only' => ['1234567890123456', 'too easy to guess'];
        yield 'letters only, short' => ['abcdefgh', 'too easy to guess'];
        yield 'one character repeated' => [str_repeat('a', 20), 'too easy to guess'];
        yield 'over the maximum' => [str_repeat('Ab3!', 17), 'must be 8 to 64 characters'];
        yield 'control character' => ["Sigil-2026\t!", 'cannot be typed'];
        yield 'invalid utf-8' => ["Sigil-2026\xff!", 'cannot be typed'];
    }

    #[DataProvider('rejected')]
    public function testRejectsAWeakOrMalformedPin(string $pin, string $message): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage($message);
        PinPolicy::assert($pin);
    }
}
