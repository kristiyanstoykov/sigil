<?php

declare(strict_types=1);

namespace App\Tests\Functional\AuditLog;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Enum\AuditSeverity;
use App\Document\Service\DocumentUploader;
use App\Tests\Functional\AuthWebTestCase;

/**
 * /audit shows a person what they did and what happened to what is theirs -
 * and not what strangers did to their own things.
 */
final class AuditLogPageTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testTheLogShowsMyActionsAndWhatHappenedToMyDocumentsAndNothingElse(): void
    {
        $me = $this->createUser($this->uniqueEmail('audit-me'), totpEnabled: true);
        $other = $this->createUser($this->uniqueEmail('audit-other'), totpEnabled: true);
        $mine = static::getContainer()->get(DocumentUploader::class)->upload($me, self::MINIMAL_PDF, 'Mine.pdf');
        $theirs = static::getContainer()->get(DocumentUploader::class)->upload($other, self::MINIMAL_PDF, 'Theirs.pdf');

        // Someone else acting on my document is on my record; their own document is not.
        $audit = static::getContainer()->get(AuditLoggerInterface::class);
        $audit->log('document.accessed', $other, ['versionNumber' => 1], 'Document', $mine->getId()->toRfc4122());
        $audit->log('document.accessed', $other, ['versionNumber' => 1], 'Document', $theirs->getId()->toRfc4122());
        $audit->log('certificate.pin_failed', $other, ['failedAttempts' => 1], 'Certificate', 'not-mine', AuditSeverity::Warning);

        $this->loginFully($me->getEmail());
        $crawler = $this->client->request('GET', '/audit');
        self::assertResponseIsSuccessful();
        $html = $crawler->html();

        self::assertStringContainsString('Uploaded a document', $html);
        self::assertStringContainsString('Mine.pdf', $html);
        self::assertStringNotContainsString('Theirs.pdf', $html);
        self::assertSame(1, $crawler->filter('li:contains("Opened a document")')->count(), 'the stranger opening MY file is on my record; them opening theirs is not');
        self::assertSame(1, $crawler->filter('li:contains("Uploaded a document")')->count(), 'my upload, not theirs');
        self::assertStringNotContainsString('Wrong PIN', $html, "another user's certificate is not my business");
        self::assertStringContainsString('Hash chain', $html);
    }

    public function testFiltersAreLinksAndVerifyIsAPost(): void
    {
        $me = $this->createUser($this->uniqueEmail('audit-filter'), totpEnabled: true);
        static::getContainer()->get(DocumentUploader::class)->upload($me, self::MINIMAL_PDF, 'Filtered.pdf');
        $this->loginFully($me->getEmail());

        $crawler = $this->client->request('GET', '/audit?family=certificates');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nothing matches', $crawler->html(), 'an upload is not a certificate event');

        $crawler = $this->client->request('GET', '/audit?family=documents');
        self::assertStringContainsString('Uploaded a document', $crawler->html());

        $form = $crawler->filter('form[action$="/audit/verify"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/audit');
        $html = $this->client->followRedirect()->html();
        self::assertMatchesRegularExpression('/Audit chain (intact|BROKEN)/', $html, 'the verdict is reported either way');
    }
}
