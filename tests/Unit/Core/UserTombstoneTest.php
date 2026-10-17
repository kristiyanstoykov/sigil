<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTombstoneTest extends TestCase
{
    public function testTombstoneStripsEveryPersonalField(): void
    {
        $user = (new User())
            ->setEmail('ana@example.com')
            ->setPassword('$argon2id$hash')
            ->setFirstName('Ana')
            ->setLastName('Ivanova')
            ->setCompany('Acme')
            ->setPosition('Legal')
            ->setGoogleAuthenticatorSecret('sealed:secret')
            ->enableTotp();
        $user->initUuid();
        $user->recordTotpCode('123456', new \DateTimeImmutable());

        $at = new \DateTimeImmutable('2026-10-17 12:00:00');
        $user->tombstone($at, 'email-fingerprint/v1:abc', 'pepper:v1$hash');

        self::assertTrue($user->isDeleted());
        self::assertSame($at, $user->getDeletedAt());
        self::assertSame('deleted-'.$user->getId()->toRfc4122().'@invalid', $user->getEmail());
        self::assertSame('', $user->getPassword());
        self::assertSame('A removed account', $user->getFullName());
        self::assertNull($user->getCompany());
        self::assertNull($user->getPosition());
        self::assertNull($user->getGoogleAuthenticatorSecret());
        self::assertFalse($user->isGoogleAuthenticatorEnabled());
        self::assertFalse($user->wasTotpCodeUsed('123456', new \DateTimeImmutable()));
        self::assertSame('email-fingerprint/v1:abc', $user->getEmailFingerprint());
        self::assertSame('pepper:v1$hash', $user->getRecoveryCodeHash());
    }
}
