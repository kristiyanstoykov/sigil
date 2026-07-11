<?php

declare(strict_types=1);

namespace App\Tests\Functional\Document;

use App\Core\Exception\DomainException;
use App\Document\Enum\DocumentVersionKind;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentStorageInterface;
use App\Document\Service\DocumentUploader;
use App\Tests\Functional\AuthWebTestCase;

class DocumentUploadTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    private function uploader(): DocumentUploader
    {
        return static::getContainer()->get(DocumentUploader::class);
    }

    private function downloader(): DocumentDownloader
    {
        return static::getContainer()->get(DocumentDownloader::class);
    }

    public function testUploadEncryptsPersistsAndRoundTrips(): void
    {
        $owner = $this->createUser($this->uniqueEmail('upload'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, '../../etc/My Contract.pdf');

        // Title is sanitized (no path), owner set, one original version.
        self::assertSame('My Contract.pdf', $document->getTitle());
        self::assertSame($owner, $document->getOwner());
        $version = $document->getLatestVersion();
        self::assertNotNull($version);
        self::assertSame(1, $version->getVersionNumber());
        self::assertSame(DocumentVersionKind::Original, $version->getKind());
        self::assertSame(\strlen(self::MINIMAL_PDF), $version->getSizeBytes());

        // contentHash is a keyed HMAC-SHA-384 (96 hex chars), NOT a plain sha384.
        self::assertMatchesRegularExpression('/^[0-9a-f]{96}$/', $version->getContentHash());
        self::assertNotSame(hash('sha384', self::MINIMAL_PDF), $version->getContentHash());

        // Storage holds ciphertext only, under a backend-stamped key.
        $storage = static::getContainer()->get(DocumentStorageInterface::class);
        self::assertMatchesRegularExpression('/^[a-z0-9]+:/', $version->getStorageKey());
        self::assertStringNotContainsString('%PDF', $storage->retrieve($version->getStorageKey()));

        // Owner can decrypt back to the exact original bytes.
        self::assertSame(self::MINIMAL_PDF, $this->downloader()->download($version, $owner));
    }

    public function testUserWithoutGrantCannotDownload(): void
    {
        $owner = $this->createUser($this->uniqueEmail('owner'));
        $stranger = $this->createUser($this->uniqueEmail('stranger'));

        $document = $this->uploader()->upload($owner, self::MINIMAL_PDF, 'secret.pdf');
        $version = $document->getLatestVersion();
        self::assertNotNull($version);

        $this->expectException(DomainException::class);
        $this->downloader()->download($version, $stranger);
    }

    public function testNonPdfIsRejected(): void
    {
        $owner = $this->createUser($this->uniqueEmail('nonpdf'));

        $this->expectException(DomainException::class);
        $this->uploader()->upload($owner, 'this is plainly not a pdf', 'evil.pdf');
    }

    public function testOversizeIsRejected(): void
    {
        $owner = $this->createUser($this->uniqueEmail('oversize'));
        $tooBig = '%PDF-1.4'.str_repeat('A', 11 * 1024 * 1024);

        $this->expectException(DomainException::class);
        $this->uploader()->upload($owner, $tooBig, 'huge.pdf');
    }
}
