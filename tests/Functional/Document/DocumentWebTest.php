<?php

declare(strict_types=1);

namespace App\Tests\Functional\Document;

use App\Tests\Functional\AuthWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * End-to-end web flow for the Documents UI: list renders (empty + populated),
 * the upload modal posts and encrypts, download streams the decrypted PDF, and
 * another user cannot reach someone else's document.
 */
final class DocumentWebTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    private function loginFully(string $email): void
    {
        $this->submitLogin($email, self::PASSWORD);
        $crawler = $this->client->request('GET', '/2fa');
        $form = $crawler->filter('form[action$="2fa_check"]')->form([
            '_auth_code' => $this->totpCode(self::TOTP_SECRET),
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();
    }

    /** Uploads a PDF and returns the new document's detail URL. */
    private function uploadPdf(string $name): string
    {
        $crawler = $this->client->request('GET', '/documents');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action$="/documents/upload"] input[name="_token"]')->attr('value');

        $tmp = tempnam(sys_get_temp_dir(), 'sigil-pdf');
        file_put_contents($tmp, self::MINIMAL_PDF);
        $file = new UploadedFile($tmp, $name, 'application/pdf', null, true);

        $this->client->request('POST', '/documents/upload', ['_token' => $token], ['document' => $file]);

        // A successful upload continues to the sign page, not back to where the
        // modal was opened from: storing a document is a step, not the goal.
        self::assertResponseRedirects();
        $signUrl = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/documents/[0-9a-f-]+/sign$#', $signUrl);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        return substr($signUrl, 0, -\strlen('/sign'));
    }

    public function testEmptyStateThenUploadAndDownload(): void
    {
        $email = $this->uniqueEmail('docweb');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->loginFully($email);

        // Empty state.
        $this->client->request('GET', '/documents');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No documents yet', (string) $this->client->getResponse()->getContent());

        // Upload → detail page renders.
        $showUrl = $this->uploadPdf('Contract.pdf');
        $this->client->request('GET', $showUrl);
        self::assertResponseIsSuccessful();
        $show = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Contract.pdf', $show);
        self::assertStringContainsString('Integrity fingerprint', $show);

        // An unsigned document reads as unfinished, and both ways out are shown:
        // sign yourself (live) and request a signature (the ADR-007 seam).
        self::assertStringContainsString('still a draft', $show);
        self::assertStringContainsString('Request a signature', $show);
        self::assertStringContainsString($showUrl.'/sign', $show);

        // List now shows the document, badged Draft.
        $this->client->request('GET', '/documents');
        $list = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Contract.pdf', $list);
        self::assertStringContainsString('Draft', $list);

        // Download streams the decrypted PDF.
        $this->client->request('GET', $showUrl.'/download');
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(self::MINIMAL_PDF, (string) $this->client->getResponse()->getContent());
    }

    public function testAnotherUserGets404(): void
    {
        $owner = $this->uniqueEmail('docowner');
        $this->createUser($owner, verified: true, totpEnabled: true);
        $this->loginFully($owner);
        $showUrl = $this->uploadPdf('Private.pdf');

        // A different, fully-authenticated user must not reach it.
        $other = $this->uniqueEmail('docother');
        $this->createUser($other, verified: true, totpEnabled: true);
        $this->client->getCookieJar()->clear(); // drop the owner's session first
        $this->loginFully($other);

        $this->client->request('GET', $showUrl);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $showUrl.'/download');
        self::assertResponseStatusCodeSame(404);
    }

    public function testNonPdfUploadIsRejected(): void
    {
        $email = $this->uniqueEmail('docreject');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->loginFully($email);

        $crawler = $this->client->request('GET', '/documents');
        $token = $crawler->filter('form[action$="/documents/upload"] input[name="_token"]')->attr('value');

        $tmp = tempnam(sys_get_temp_dir(), 'sigil-bad');
        file_put_contents($tmp, 'not a pdf at all');
        $file = new UploadedFile($tmp, 'evil.pdf', 'application/pdf', null, true);

        $this->client->request('POST', '/documents/upload', ['_token' => $token], ['document' => $file]);
        $this->client->followRedirect();
        self::assertStringContainsString('Only PDF files are accepted', (string) $this->client->getResponse()->getContent());
    }

    public function testOversizeUploadShowsClearSizeError(): void
    {
        $email = $this->uniqueEmail('docbig');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->loginFully($email);

        $crawler = $this->client->request('GET', '/documents');
        $token = $crawler->filter('form[action$="/documents/upload"] input[name="_token"]')->attr('value');

        // Simulate PHP flagging the upload as over its size limit (UPLOAD_ERR_INI_SIZE).
        $tmp = tempnam(sys_get_temp_dir(), 'sigil-big');
        file_put_contents($tmp, '%PDF-1.4');
        $file = new UploadedFile($tmp, 'huge.pdf', 'application/pdf', \UPLOAD_ERR_INI_SIZE, true);

        $this->client->request('POST', '/documents/upload', ['_token' => $token], ['document' => $file]);
        $this->client->followRedirect();
        self::assertStringContainsString('too large', (string) $this->client->getResponse()->getContent());
    }
}
