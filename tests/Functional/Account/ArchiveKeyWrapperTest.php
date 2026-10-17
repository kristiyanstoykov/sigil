<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Service\ArchiveKeyWrapper;
use App\Core\Crypto\Exception\DecryptionFailedException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The archive key wraps a KEK in its own token, bound to the owner's id, and
 * refuses any blob it did not produce (ADR-015 §2). Runs against the real
 * `sigil-archive` token that `docker/bootstrap.sh test` provisions.
 */
final class ArchiveKeyWrapperTest extends KernelTestCase
{
    public function testAKekRoundTripsOnlyForItsOwner(): void
    {
        self::bootKernel();
        $archive = static::getContainer()->get(ArchiveKeyWrapper::class);
        $kek = random_bytes(32);

        $wrapped = $archive->wrapKek($kek, 'owner-a');

        self::assertSame("\x03", $wrapped[0]);
        self::assertStringNotContainsString($kek, $wrapped);
        self::assertSame($kek, $archive->unwrapKek($wrapped, 'owner-a'));

        $this->expectException(DecryptionFailedException::class);
        $archive->unwrapKek($wrapped, 'owner-b');
    }

    public function testARootWrappedBlobIsRefused(): void
    {
        self::bootKernel();
        $archive = static::getContainer()->get(ArchiveKeyWrapper::class);
        $wrapped = $archive->wrapKek(random_bytes(32), 'owner-a');

        $this->expectException(DecryptionFailedException::class);
        $archive->unwrapKek("\x02".substr($wrapped, 1), 'owner-a');
    }
}
