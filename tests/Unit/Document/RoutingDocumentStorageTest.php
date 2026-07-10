<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Core\Exception\DomainException;
use App\Document\Service\RoutingDocumentStorage;
use App\Document\Service\StorageBackendInterface;
use App\Document\Service\StorageBackendRegistry;
use App\Document\Service\StorageKeyGenerator;
use PHPUnit\Framework\TestCase;

final class RoutingDocumentStorageTest extends TestCase
{
    private InMemoryBackend $minio;
    private InMemoryBackend $aws;

    private function router(string $activeId): RoutingDocumentStorage
    {
        $this->minio = new InMemoryBackend('minio');
        $this->aws = new InMemoryBackend('aws');
        $registry = new StorageBackendRegistry([$this->minio, $this->aws], $activeId);

        return new RoutingDocumentStorage($registry, new StorageKeyGenerator());
    }

    public function testWriteGoesToActiveBackendAndKeyIsStamped(): void
    {
        $router = $this->router('minio');

        $key = $router->store('ciphertext');

        self::assertStringStartsWith('minio:', $key);
        self::assertCount(1, $this->minio->objects);
        self::assertCount(0, $this->aws->objects);
        self::assertSame('ciphertext', $router->retrieve($key));
    }

    public function testReadRoutesByStampNotByActiveBackend(): void
    {
        // Write while minio is active...
        $router = $this->router('minio');
        $minioKey = $router->store('on-minio');

        // ...then a new router with aws active must still read the minio object.
        $registry = new StorageBackendRegistry([$this->minio, $this->aws], 'aws');
        $switched = new RoutingDocumentStorage($registry, new StorageKeyGenerator());

        self::assertSame('on-minio', $switched->retrieve($minioKey));

        // And a new write now lands on aws.
        $awsKey = $switched->store('on-aws');
        self::assertStringStartsWith('aws:', $awsKey);
        self::assertSame('on-aws', $switched->retrieve($awsKey));
    }

    public function testDeleteAndExistsRouteByStamp(): void
    {
        $router = $this->router('minio');
        $key = $router->store('x');

        self::assertTrue($router->exists($key));
        $router->delete($key);
        self::assertFalse($router->exists($key));
    }

    public function testMalformedKeyIsRejected(): void
    {
        $router = $this->router('minio');

        $this->expectException(DomainException::class);
        $router->retrieve('no-backend-stamp-here');
    }

    public function testUnknownBackendIsRejected(): void
    {
        $router = $this->router('minio');

        $this->expectException(DomainException::class);
        $router->retrieve('s3glacier:ab/abcdef0123456789');
    }

    public function testActiveBackendMustExist(): void
    {
        $router = $this->router('does-not-exist');

        $this->expectException(DomainException::class);
        $router->store('x');
    }
}

/**
 * Minimal in-memory {@see StorageBackendInterface} for routing tests.
 */
final class InMemoryBackend implements StorageBackendInterface
{
    /** @var array<string, string> */
    public array $objects = [];

    public function __construct(private readonly string $id)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function put(string $objectKey, string $ciphertext): void
    {
        $this->objects[$objectKey] = $ciphertext;
    }

    public function get(string $objectKey): string
    {
        return $this->objects[$objectKey] ?? throw new DomainException('missing');
    }

    public function remove(string $objectKey): void
    {
        unset($this->objects[$objectKey]);
    }

    public function has(string $objectKey): bool
    {
        return isset($this->objects[$objectKey]);
    }
}
