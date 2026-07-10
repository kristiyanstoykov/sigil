<?php

declare(strict_types=1);

namespace App\Document\Service;

use App\Core\Exception\DomainException;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\S3\S3Client;

/**
 * S3-compatible storage backend (ADR-009). One instance per backend: MinIO in
 * dev, real AWS S3 in prod - same class, different endpoint/credentials. The
 * client is built lazily, so an unconfigured backend (e.g. AWS before real
 * credentials are supplied) never breaks the app - it is only touched when a
 * storage key actually routes to it.
 *
 * Only ciphertext is ever put here (ADR-004).
 */
final class S3StorageBackend implements StorageBackendInterface
{
    private ?S3Client $client = null;

    public function __construct(
        private readonly string $id,
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly bool $pathStyleEndpoint = true,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function put(string $objectKey, string $ciphertext): void
    {
        try {
            $this->client()->putObject([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
                'Body' => $ciphertext,
            ])->resolve();
        } catch (HttpException $e) {
            throw new DomainException('Failed to store object.', 0, $e);
        }
    }

    public function get(string $objectKey): string
    {
        try {
            $result = $this->client()->getObject([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
            ]);

            return $result->getBody()->getContentAsString();
        } catch (HttpException $e) {
            throw new DomainException('Stored document not found.', 0, $e);
        }
    }

    public function remove(string $objectKey): void
    {
        try {
            $this->client()->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $objectKey,
            ])->resolve();
        } catch (HttpException $e) {
            throw new DomainException('Failed to delete object.', 0, $e);
        }
    }

    public function has(string $objectKey): bool
    {
        return $this->client()->objectExists([
            'Bucket' => $this->bucket,
            'Key' => $objectKey,
        ])->isSuccess();
    }

    /** Create the bucket if it does not already exist. Idempotent - used by sigil:storage:init. */
    public function ensureBucket(): void
    {
        $client = $this->client();
        if ($client->bucketExists(['Bucket' => $this->bucket])->isSuccess()) {
            return;
        }

        try {
            $client->createBucket(['Bucket' => $this->bucket])->resolve();
        } catch (HttpException $e) {
            throw new DomainException(sprintf('Could not create bucket "%s".', $this->bucket), 0, $e);
        }
    }

    public function bucket(): string
    {
        return $this->bucket;
    }

    private function client(): S3Client
    {
        if (null !== $this->client) {
            return $this->client;
        }

        // AsyncAws config values are strings; empty endpoint/creds are omitted so
        // an unconfigured backend defaults cleanly (real AWS resolves creds from
        // the environment/instance role when AWS_* are left blank).
        $config = [
            'region' => $this->region,
            'pathStyleEndpoint' => $this->pathStyleEndpoint ? 'true' : 'false',
        ];
        if ('' !== $this->endpoint) {
            $config['endpoint'] = $this->endpoint;
        }
        if ('' !== $this->accessKey) {
            $config['accessKeyId'] = $this->accessKey;
        }
        if ('' !== $this->secretKey) {
            $config['accessKeySecret'] = $this->secretKey;
        }

        return $this->client = new S3Client($config);
    }
}
