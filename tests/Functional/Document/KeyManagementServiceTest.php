<?php

declare(strict_types=1);

namespace App\Tests\Functional\Document;

use App\Core\Crypto\Exception\DecryptionFailedException;
use App\Document\Repository\UserEncryptionKeyRepository;
use App\Document\Service\KeyManagementService;
use App\Tests\Functional\AuthWebTestCase;

class KeyManagementServiceTest extends AuthWebTestCase
{
    private function service(): KeyManagementService
    {
        return static::getContainer()->get(KeyManagementService::class);
    }

    public function testKekIsCreatedOnceAndStable(): void
    {
        $user = $this->createUser($this->uniqueEmail('kek'));
        $service = $this->service();

        $kek1 = $service->userKek($user);
        $kek2 = $service->userKek($user);

        self::assertSame(32, \strlen($kek1));
        self::assertSame($kek1, $kek2, 'KEK is stable across calls');

        // Exactly one persisted row, and it is wrapped (not the raw key).
        $record = static::getContainer()->get(UserEncryptionKeyRepository::class)->findForUser($user);
        self::assertNotNull($record);
        self::assertStringNotContainsString($kek1, base64_decode($record->getWrappedKek()));
    }

    public function testInsertIfAbsentPreservesTheFirstKek(): void
    {
        $user = $this->createUser($this->uniqueEmail('race'));
        $service = $this->service();
        $repo = static::getContainer()->get(UserEncryptionKeyRepository::class);

        $kek1 = $service->userKek($user); // creates the row

        // Simulate a losing concurrent insert with a different value - the
        // ON CONFLICT DO NOTHING must ignore it, leaving the first KEK intact.
        $repo->insertIfAbsent($user, base64_encode('would-be-second-kek-envelope'));

        self::assertSame($kek1, $service->userKek($user));
    }

    public function testDekWrapRoundTripsUnderUserKek(): void
    {
        $user = $this->createUser($this->uniqueEmail('dek'));
        $service = $this->service();

        $dek = random_bytes(32);
        $wrapped = $service->wrapDek($user, $dek, 'version-1');

        self::assertSame($dek, $service->unwrapDek($user, $wrapped, 'version-1'));
    }

    public function testDekUnwrapRequiresMatchingAad(): void
    {
        $user = $this->createUser($this->uniqueEmail('aad'));
        $service = $this->service();

        $wrapped = $service->wrapDek($user, random_bytes(32), 'version-1');

        $this->expectException(DecryptionFailedException::class);
        $service->unwrapDek($user, $wrapped, 'version-2');
    }

    public function testAnotherUserCannotUnwrapTheDek(): void
    {
        $owner = $this->createUser($this->uniqueEmail('owner'));
        $other = $this->createUser($this->uniqueEmail('other'));
        $service = $this->service();

        $wrapped = $service->wrapDek($owner, random_bytes(32), 'v');

        $this->expectException(DecryptionFailedException::class);
        $service->unwrapDek($other, $wrapped, 'v');
    }
}
