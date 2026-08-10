<?php

declare(strict_types=1);

namespace App\Tests\Functional\Document;

use App\Document\Form\ShareDocumentForm;
use App\Document\Service\DocumentUploader;
use App\Tests\Functional\AuthWebTestCase;

/**
 * The sharing UI end to end: the owner shares by email, the recipient finds the
 * document under "Shared with me" and can open and download it, the owner takes
 * access away again, and nobody but the owner can share or revoke.
 */
final class DocumentSharingWebTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    /**
     * Seeds owner + recipient and one uploaded document BEFORE logging in: the
     * web client reboots the kernel on login, detaching earlier entities.
     *
     * @return array{0: string, 1: string, 2: string} [documentId, ownerEmail, recipientEmail]
     */
    private function seed(string $prefix): array
    {
        $ownerEmail = $this->uniqueEmail($prefix.'-owner');
        $recipientEmail = $this->uniqueEmail($prefix.'-recipient');
        $owner = $this->createUser($ownerEmail, verified: true, totpEnabled: true);
        $this->createUser($recipientEmail, verified: true, totpEnabled: true);

        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($owner, self::MINIMAL_PDF, 'Shared Agreement.pdf');

        return [$document->getId()->toRfc4122(), $ownerEmail, $recipientEmail];
    }

    /** Submits the real share form the way a browser would (CSRF token included). */
    private function submitShare(string $documentId, string $email): void
    {
        $crawler = $this->client->request('GET', '/documents/'.$documentId);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action$="/share"]')->form();
        $form['share_document_form['.ShareDocumentForm::E_EMAIL.']'] = $email;
        $this->client->submit($form);
    }

    private function switchUser(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $this->loginFully($email);
    }

    public function testOwnerSharesAndTheRecipientCanOpenAndDownloadIt(): void
    {
        [$documentId, $ownerEmail, $recipientEmail] = $this->seed('web-share');
        $this->loginFully($ownerEmail);

        $this->submitShare($documentId, $recipientEmail);
        self::assertResponseRedirects('/documents/'.$documentId);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Shared with '.$recipientEmail, $crawler->html());
        // The recipient is now listed, with a way to take it back.
        self::assertGreaterThan(0, $crawler->filter('form[action$="/share/revoke"]')->count());

        $this->switchUser($recipientEmail);

        // Someone else's document never appears in their own list...
        $crawler = $this->client->request('GET', '/documents');
        self::assertStringNotContainsString('Shared Agreement.pdf', $crawler->html());

        // ...only under "Shared with me", attributed to the owner.
        $crawler = $this->client->request('GET', '/documents?tab=shared');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Shared Agreement.pdf', $crawler->html());

        $crawler = $this->client->request('GET', '/documents/'.$documentId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Shared by', $crawler->html());
        // Read-only: no signing, no sharing on.
        self::assertSame(0, $crawler->filter('a[href$="/sign"]')->count());
        self::assertSame(0, $crawler->filter('form[action$="/share"]')->count());

        $this->client->request('GET', '/documents/'.$documentId.'/download');
        self::assertResponseIsSuccessful();
        self::assertSame(self::MINIMAL_PDF, (string) $this->client->getResponse()->getContent());
    }

    public function testRevokeClosesTheDoorAgain(): void
    {
        [$documentId, $ownerEmail, $recipientEmail] = $this->seed('web-revoke');
        $this->loginFully($ownerEmail);

        $this->submitShare($documentId, $recipientEmail);

        // Revoke is a form too (RevokeShareForm) - it just has nothing to type.
        // Submitting it as rendered proves the hidden target and CSRF line up.
        $crawler = $this->client->request('GET', '/documents/'.$documentId);
        $this->client->submit($crawler->filter('form[action$="/share/revoke"]')->form());
        self::assertResponseRedirects('/documents/'.$documentId);

        $this->switchUser($recipientEmail);

        $crawler = $this->client->request('GET', '/documents?tab=shared');
        self::assertStringNotContainsString('Shared Agreement.pdf', $crawler->html());

        // Back to invisible - 404, not 403: the id is not confirmed to exist.
        $this->client->request('GET', '/documents/'.$documentId);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/documents/'.$documentId.'/download');
        self::assertResponseStatusCodeSame(404);
    }

    public function testARecipientCannotShareItOnwards(): void
    {
        [$documentId, $ownerEmail, $recipientEmail] = $this->seed('web-passon');
        $strangerEmail = $this->uniqueEmail('web-passon-stranger');
        $this->createUser($strangerEmail, verified: true, totpEnabled: true);

        $this->loginFully($ownerEmail);
        $this->submitShare($documentId, $recipientEmail);

        $this->switchUser($recipientEmail);

        // Sharing is owner-only, and a non-owner is not told the route exists.
        $this->client->request('POST', '/documents/'.$documentId.'/share', [
            'share_document_form' => [ShareDocumentForm::E_EMAIL => $strangerEmail],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The error belongs under the field, not in a toast - and the page comes
     * back 422 so Turbo swaps it in instead of dropping the response.
     */
    public function testSharingWithAnUnknownAddressIsRefusedWithAFieldError(): void
    {
        [$documentId, $ownerEmail] = $this->seed('web-unknown');
        $this->loginFully($ownerEmail);

        $this->submitShare($documentId, 'nobody@nowhere.invalid');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'No Sigil account uses that email address',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testAMalformedAddressNeverReachesTheSharer(): void
    {
        [$documentId, $ownerEmail] = $this->seed('web-malformed');
        $this->loginFully($ownerEmail);

        $this->submitShare($documentId, 'definitely-not-an-email');

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'does not look like an email address',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testShareWithoutAValidCsrfTokenIsRefused(): void
    {
        [$documentId, $ownerEmail, $recipientEmail] = $this->seed('web-csrf');
        $this->loginFully($ownerEmail);

        $crawler = $this->client->request('GET', '/documents/'.$documentId);
        $form = $crawler->filter('form[action$="/share"]')->form();
        $form['share_document_form['.ShareDocumentForm::E_EMAIL.']'] = $recipientEmail;
        $form['share_document_form[_token]'] = 'not-the-token';
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);

        // And nothing was shared: the recipient still cannot see it.
        $this->switchUser($recipientEmail);
        $this->client->request('GET', '/documents/'.$documentId);
        self::assertResponseStatusCodeSame(404);
    }
}
