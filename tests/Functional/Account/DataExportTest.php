<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Entity\DataExport;
use App\Account\Enum\DataExportStatus;
use App\Account\Message\BuildDataExport;
use App\Account\Message\BuildDataExportHandler;
use App\Account\Repository\DataExportRepository;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Core\Entity\User;
use App\Document\Service\DocumentStorageInterface;
use App\Document\Service\LocalStorageBackend;
use App\Document\Enum\DocumentVersionKind;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
use App\Notification\Enum\NotificationType;
use App\Notification\Repository\NotificationRepository;
use App\TempDownload\Entity\TempDownload;
use App\TempDownload\Repository\TempDownloadRepository;
use App\TempDownload\Service\TempDownloadService;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * "Download all" end to end (ADR-015 §7-8): request on the page, the worker's
 * handler builds zip parts, each part downloads once for its owner and nobody
 * else, and the sweep clears what is spent or expired.
 */
class DataExportTest extends AuthWebTestCase
{
    /** Padded so two of them overflow the test env's 2 KB part limit. */
    private static function pdf(string $marker): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
            ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
            ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
            .'% '.$marker.' '.str_repeat('x', 1200)."\n"
            ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";
    }

    public function testTheExportIsBuiltSplitIntoPartsAndEachPartDownloadsOnce(): void
    {
        $email = $this->uniqueEmail('owner');
        $owner = $this->createUser($email, verified: true, totpEnabled: true);
        $uploader = static::getContainer()->get(DocumentUploader::class);
        $uploader->upload($owner, self::pdf('first'), 'Договор.pdf');
        $uploader->upload($owner, self::pdf('second'), 'Invoice.pdf');

        $this->loginFully($email);
        $export = $this->requestExportThroughThePage();
        self::assertSame(DataExportStatus::Queued, $export->getStatus());

        $this->runBuild($export);
        $export = $this->reload($export);
        self::assertSame(DataExportStatus::Ready, $export->getStatus());
        self::assertSame(2, $export->getFileCount());
        self::assertSame(2, $export->getPartCount(), 'two padded PDFs overflow one 2 KB part');

        $crawler = $this->client->request('GET', '/account/export');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('form[action^="/downloads/"]'));
        self::assertStringContainsString('part-1-of-2.zip', $crawler->text());

        $found = [];
        $titles = [];
        foreach ($crawler->filter('form[action^="/downloads/"]')->extract(['action']) as $action) {
            $form = $crawler->filter('form[action="'.$action.'"]')->form();

            $this->client->submit($form);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'application/zip');
            self::assertSame(\strlen((string) $this->client->getResponse()->getContent()), (int) $this->client->getResponse()->headers->get('Content-Length'), 'the page loader shows progress from it');
            self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

            $zip = $this->openZip((string) $this->client->getResponse()->getContent());
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: \JSON_THROW_ON_ERROR);
            foreach ($manifest['files'] as $file) {
                $found[] = (string) $zip->getFromName($file['path']);
                $titles[] = $file['title'];
                self::assertSame('owner', $file['role']);
            }
            $zip->close();

            // The one download is spent: the same form again finds nothing.
            $this->client->submit($form);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        }

        sort($found);
        self::assertSame([self::pdf('first'), self::pdf('second')], $found, 'the decrypted originals, byte for byte');
        self::assertContains('Договор.pdf', $titles);

        $crawler = $this->client->request('GET', '/account/export');
        self::assertCount(0, $crawler->filter('form[action^="/downloads/"]'));
        self::assertCount(2, $crawler->filter('.badge:contains("Downloaded")'));

        $notifications = static::getContainer()->get(NotificationRepository::class)->findBy(['recipient' => $owner, 'type' => NotificationType::DataExportReady]);
        self::assertCount(1, $notifications);

        $audit = static::getContainer()->get(AuditLogEntryRepository::class);
        self::assertNotNull($audit->findOneBy(['action' => 'data_export.ready', 'subjectId' => $export->getId()->toRfc4122()]));
    }

    public function testOnlyTheLatestVersionOfADocumentIsExported(): void
    {
        $email = $this->uniqueEmail('owner');
        $owner = $this->createUser($email, verified: true, totpEnabled: true);
        $container = static::getContainer();
        $document = $container->get(DocumentUploader::class)->upload($owner, self::pdf('original'), 'Contract.pdf');
        $container->get(DocumentVersionWriter::class)->write($document, $owner, self::pdf('signed'), DocumentVersionKind::Signed, 'document.signed');

        $this->loginFully($email);
        $export = $this->requestExportThroughThePage();
        $this->runBuild($export);
        self::assertSame(1, $this->reload($export)->getFileCount());

        $crawler = $this->client->request('GET', '/account/export');
        $this->client->submit($crawler->filter('form[action^="/downloads/"]')->form());
        self::assertResponseIsSuccessful();

        $zip = $this->openZip((string) $this->client->getResponse()->getContent());
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $manifest['files']);
        self::assertSame(2, $manifest['files'][0]['version']);
        self::assertSame('signed', $manifest['files'][0]['kind']);
        self::assertSame(self::pdf('signed'), $zip->getFromName($manifest['files'][0]['path']));
        $zip->close();
    }

    public function testSomeoneElseCannotTakeTheDownload(): void
    {
        $email = $this->uniqueEmail('owner');
        $owner = $this->createUser($email, verified: true, totpEnabled: true);
        static::getContainer()->get(DocumentUploader::class)->upload($owner, self::pdf('mine'), 'Mine.pdf');
        $this->loginFully($email);
        $this->runBuild($this->requestExportThroughThePage());

        $crawler = $this->client->request('GET', '/account/export');
        $form = $crawler->filter('form[action^="/downloads/"]')->form();

        $strangerEmail = $this->uniqueEmail('stranger');
        $this->createUser($strangerEmail, verified: true, totpEnabled: true);
        $this->client->getCookieJar()->clear();
        $this->loginFully($strangerEmail);

        // The owner's own form, CSRF token and all: still not theirs.
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $download = $this->downloadsOf($owner)[0];
        self::assertFalse($download->isConsumed(), 'a refused attempt does not spend the download');
    }

    public function testAFailedFetchDoesNotSpendTheDownload(): void
    {
        $email = $this->uniqueEmail('owner');
        $owner = $this->createUser($email, verified: true, totpEnabled: true);
        static::getContainer()->get(DocumentUploader::class)->upload($owner, self::pdf('mine'), 'Mine.pdf');
        $this->loginFully($email);
        $this->runBuild($this->requestExportThroughThePage());

        $crawler = $this->client->request('GET', '/account/export');
        $form = $crawler->filter('form[action^="/downloads/"]')->form();

        // Stand in for a fetch that dies half way (a timeout, a storage outage).
        $storage = static::getContainer()->get(DocumentStorageInterface::class);
        $storageKey = $this->downloadsOf($owner)[0]->getStorageKey();
        $ciphertext = $storage->retrieve($storageKey);
        $storage->delete($storageKey);

        $this->client->submit($form);
        self::assertResponseStatusCodeSame(Response::HTTP_INTERNAL_SERVER_ERROR);
        self::assertFalse($this->downloadsOf($owner)[0]->isConsumed(), 'nothing was handed over, so nothing is spent');

        // Once the file is back, the same download still works.
        $this->restoreCiphertext($storageKey, $ciphertext);
        $crawler = $this->client->request('GET', '/account/export');
        $this->client->submit($crawler->filter('form[action^="/downloads/"]')->form());
        self::assertResponseIsSuccessful();
        self::assertTrue($this->downloadsOf($owner)[0]->isConsumed());
    }

    public function testAnExpiredDownloadIsRefusedAndTheSweepClearsIt(): void
    {
        $email = $this->uniqueEmail('owner');
        $owner = $this->createUser($email, verified: true, totpEnabled: true);
        static::getContainer()->get(DocumentUploader::class)->upload($owner, self::pdf('old'), 'Old.pdf');
        $this->loginFully($email);
        $this->runBuild($this->requestExportThroughThePage());

        $crawler = $this->client->request('GET', '/account/export');
        $form = $crawler->filter('form[action^="/downloads/"]')->form();
        $download = $this->downloadsOf($owner)[0];
        $storageKey = $download->getStorageKey();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            "UPDATE temp_download SET expires_at = NOW() - INTERVAL '1 minute' WHERE id = :id",
            ['id' => $download->getId()->toRfc4122()],
        );

        // Refused before any sweep has run: expiry is enforced at download time.
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $crawler = $this->client->request('GET', '/account/export');
        self::assertCount(1, $crawler->filter('.badge:contains("Expired")'));

        $storage = static::getContainer()->get(DocumentStorageInterface::class);
        self::assertTrue($storage->exists($storageKey));
        self::assertGreaterThanOrEqual(1, static::getContainer()->get(TempDownloadService::class)->sweep());
        self::assertFalse($storage->exists($storageKey), 'the ciphertext goes with the row');
        self::assertSame([], $this->downloadsOf($owner));
    }

    public function testASecondExportCannotBeRequestedWhileOneIsBeingPrepared(): void
    {
        $email = $this->uniqueEmail('owner');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->loginFully($email);

        $export = $this->requestExportThroughThePage();
        $crawler = $this->client->request('GET', '/account/export');
        self::assertCount(1, $crawler->filter('[data-export-status="queued"][data-controller="export-status"]'), 'the banner refreshes itself when the export is ready');
        self::assertCount(0, $crawler->filter('form[action$="/account/export"]'), 'no second button while one is queued');

        $this->runBuild($export);
        $crawler = $this->client->request('GET', '/account/export');
        self::assertCount(1, $crawler->filter('[data-export-status="ready"]'), 'an empty export is still one archive with a manifest');
    }

    public function testTheDocumentsPageOffersDownloadAll(): void
    {
        $email = $this->uniqueEmail('owner');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->loginFully($email);

        $crawler = $this->client->request('GET', '/documents');
        self::assertCount(1, $crawler->filter('a[href="/account/export"]'));
    }

    private function requestExportThroughThePage(): DataExport
    {
        $crawler = $this->client->request('GET', '/account/export');
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[action$="/account/export"]')->form());
        self::assertResponseRedirects('/account/export');

        $messages = $this->transport()->getSent();
        $last = array_key_last($messages);
        self::assertNotNull($last);
        $message = $messages[$last]->getMessage();
        self::assertInstanceOf(BuildDataExport::class, $message);

        return static::getContainer()->get(DataExportRepository::class)->find($message->exportId) ?? self::fail('no export row');
    }

    /**
     * What the worker does with the queued message. Called with the id rather
     * than drained from the transport: the next request reboots the kernel,
     * which empties an in-memory transport.
     */
    private function runBuild(DataExport $export): void
    {
        static::getContainer()->get(BuildDataExportHandler::class)(new BuildDataExport($export->getId()->toRfc4122()));
    }

    private function transport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function reload(DataExport $export): DataExport
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find(DataExport::class, $export->getId()) ?? self::fail('export vanished');
    }

    /** @return list<TempDownload> */
    private function downloadsOf(User $owner): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return static::getContainer()->get(TempDownloadRepository::class)->findBy(['owner' => $owner->getId()]);
    }

    /** Tests store on the local backend; a key is `local:<object key>`. */
    private function restoreCiphertext(string $storageKey, string $ciphertext): void
    {
        [$backend, $objectKey] = explode(':', $storageKey, 2);
        self::assertSame('local', $backend);
        static::getContainer()->get(LocalStorageBackend::class)->put($objectKey, $ciphertext);
        self::assertTrue(static::getContainer()->get(DocumentStorageInterface::class)->exists($storageKey));
    }

    private function openZip(string $bytes): \ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'export-test-');
        self::assertNotFalse($path);
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        register_shutdown_function(static fn () => @unlink($path));

        return $zip;
    }
}
