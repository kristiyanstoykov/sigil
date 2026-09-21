<?php

declare(strict_types=1);

namespace App\Tests\Unit\Crypto;

use App\Core\Crypto\Exception\DecryptionFailedException;
use App\Core\Crypto\RootKeyWrapperSchemeInterface;
use App\Core\Crypto\RoutingRootKeyWrapper;
use PHPUnit\Framework\TestCase;

/** ADR-010 wrappers route like storage backends: active for writes, scheme byte for reads. */
final class RoutingRootKeyWrapperTest extends TestCase
{
    public function testNewWrapsUseTheActiveSchemeAndOldBlobsUnwrapWithTheirOwn(): void
    {
        $a = new FakeScheme('a', "\x01");
        $b = new FakeScheme('b', "\x02");
        $underA = (new RoutingRootKeyWrapper([$a, $b], 'a'))->wrapKek('kek', 'aad');
        self::assertSame("\x01", $underA[0]);

        $switched = new RoutingRootKeyWrapper([$a, $b], 'b');
        self::assertSame('kek', $switched->unwrapKek($underA, 'aad'), 'a blob from A still unwraps after B became active');
        self::assertSame("\x02", $switched->wrapKek('kek', 'aad')[0], 'new wraps carry B');
    }

    public function testAnUnknownSchemeByteIsAGenericDecryptionFailure(): void
    {
        $router = new RoutingRootKeyWrapper([new FakeScheme('a', "\x01")], 'a');

        $this->expectException(DecryptionFailedException::class);
        $router->unwrapKek("\x09blob", 'aad');
    }

    public function testAnUnknownActiveSchemeIsABootError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RoutingRootKeyWrapper([new FakeScheme('a', "\x01")], 'hsm');
    }
}

final class FakeScheme implements RootKeyWrapperSchemeInterface
{
    public function __construct(private readonly string $id, private readonly string $byte)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function schemeByte(): string
    {
        return $this->byte;
    }

    public function wrapKek(#[\SensitiveParameter] string $rawKek, string $aad): string
    {
        return $this->byte.$rawKek;
    }

    public function unwrapKek(string $wrappedKek, string $aad): string
    {
        if ($wrappedKek[0] !== $this->byte) {
            throw new DecryptionFailedException();
        }

        return substr($wrappedKek, 1);
    }
}
