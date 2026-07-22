<?php

declare(strict_types=1);

namespace App\Tests\Functional\Signing;

use App\Certificate\Entity\Certificate;
use App\Core\Entity\User;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentUploader;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Web-layer checks for the sign page: it renders the empty state when the user
 * has no usable certificate, renders the review + PIN form when they do, and
 * the wrong-PIN gate rejects before any token is touched.
 */
final class SigningControllerTest extends AuthWebTestCase
{
    private const PIN = '123456';
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

    private function makeCertificate(User $user): Certificate
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $certificate = new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Test Signer',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'test-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash(self::PIN, \PASSWORD_ARGON2ID),
        );
        $em->persist($certificate);
        $em->flush();

        return $certificate;
    }

    /**
     * Seeds a user, an uploaded document and (optionally) a certificate BEFORE
     * logging in - the web client reboots the kernel on login, which detaches
     * anything the previous EntityManager still held. Returns id strings only.
     *
     * @return array{0: string, 1: ?string} [documentId, certificateId]
     */
    private function seed(string $prefix, bool $withCertificate): array
    {
        $email = $this->uniqueEmail($prefix);
        $user = $this->createUser($email, verified: true, totpEnabled: true);
        $document = static::getContainer()->get(DocumentUploader::class)->upload($user, self::MINIMAL_PDF, 'Agreement.pdf');
        $certificateId = $withCertificate ? $this->makeCertificate($user)->getId()->toRfc4122() : null;
        $documentId = $document->getId()->toRfc4122();

        $this->loginFully($email);

        return [$documentId, $certificateId];
    }

    public function testSignPageShowsEmptyStateWithoutAUsableCertificate(): void
    {
        [$documentId] = $this->seed('sign-empty', withCertificate: false);

        $crawler = $this->client->request('GET', '/documents/'.$documentId.'/sign');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No usable certificate', $crawler->html());
        self::assertGreaterThan(0, $crawler->filter('a[href="/certificates/new"]')->count());
    }

    public function testSignPageRendersReviewAndPinFormWithACertificate(): void
    {
        [$documentId] = $this->seed('sign-form', withCertificate: true);

        $crawler = $this->client->request('GET', '/documents/'.$documentId.'/sign');

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('Sign document', $html);
        self::assertStringContainsString('TEST SIGNER', $html);         // the seal preview
        self::assertGreaterThan(0, $crawler->filter('select')->count()); // certificate chooser
        self::assertGreaterThan(0, $crawler->filter('input[type="password"]')->count());
    }

    public function testWrongPinIsRejectedAtTheGate(): void
    {
        [$documentId, $certificateId] = $this->seed('sign-badpin', withCertificate: true);

        $crawler = $this->client->request('GET', '/documents/'.$documentId.'/sign');
        // The upload modal form is present on every page - target the sign form.
        $form = $crawler->filter('form[action$="/sign"]')->form();
        $form['sign_document_form[certificate]'] = $certificateId;
        $form['sign_document_form[pin]'] = '000000';
        $this->client->submit($form);

        // Redirects back (PRG) so the flash shows under Turbo; no signed version.
        self::assertResponseRedirects('/documents/'.$documentId.'/sign');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Incorrect PIN', $crawler->html());

        $document = static::getContainer()->get(DocumentRepository::class)->find($documentId);
        self::assertNotNull($document);
        self::assertCount(1, $document->getVersions());
    }
}
