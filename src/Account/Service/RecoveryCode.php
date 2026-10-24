<?php

declare(strict_types=1);

namespace App\Account\Service;

/**
 * The code that proves a returning person is the one who left (ADR-015 §4):
 * 100 random bits as 20 base32 characters in four groups. Shown once and
 * emailed; only its peppered hash is stored.
 */
final class RecoveryCode
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const int LENGTH = 20;
    private const int GROUP = 5;

    public static function generate(): string
    {
        $chars = '';
        for ($i = 0; $i < self::LENGTH; ++$i) {
            $chars .= self::ALPHABET[random_int(0, 31)];
        }

        return implode('-', str_split($chars, self::GROUP));
    }

    /** What is hashed and compared: upper case, no separators or spaces. */
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $code));
    }
}
