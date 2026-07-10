<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Core\Exception\DomainException;
use App\Document\Service\LocalStorageBackend;
use App\Document\Service\StorageKeyGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocalStorageBackendTest extends TestCase
{
    private string $baseDir;
    private LocalStorageBackend $backend;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir().'/sigil-storage-test-'.bin2hex(random_bytes(6));
        $this->backend = new LocalStorageBackend($this->baseDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->baseDir);
    }

    public function testIdIsLocal(): void
    {
        self::assertSame('local', $this->backend->id());
    }

    public function testPutGetRoundTrip(): void
    {
        $key = (new StorageKeyGenerator())->generate();
        $bytes = random_bytes(1024);

        self::assertFalse($this->backend->has($key));

        $this->backend->put($key, $bytes);

        self::assertTrue($this->backend->has($key));
        self::assertSame($bytes, $this->backend->get($key));
    }

    public function testRemove(): void
    {
        $key = (new StorageKeyGenerator())->generate();
        $this->backend->put($key, 'data');

        $this->backend->remove($key);

        self::assertFalse($this->backend->has($key));
    }

    public function testGetUnknownKeyThrows(): void
    {
        $key = (new StorageKeyGenerator())->generate();

        $this->expectException(DomainException::class);
        $this->backend->get($key);
    }

    public function testFanOutDirectoryMatchesKeyPrefix(): void
    {
        $key = (new StorageKeyGenerator())->generate();
        $this->backend->put($key, 'x');

        self::assertFileExists($this->baseDir.'/'.$key);
        self::assertSame(substr($key, 0, 2), explode('/', $key)[0]);
    }

    #[DataProvider('maliciousKeys')]
    public function testMalformedOrTraversingKeysAreRejected(string $key): void
    {
        $this->expectException(DomainException::class);
        $this->backend->put($key, 'x');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function maliciousKeys(): iterable
    {
        yield 'traversal' => ['../../etc/passwd'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'embedded traversal' => ['ab/../../../etc/passwd'];
        yield 'wrong shape' => ['not-a-valid-key'];
        yield 'uppercase hex' => ['AB/ABCDEF0123456789'];
        yield 'empty' => [''];
    }
}
