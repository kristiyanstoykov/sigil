<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Certificate\Entity\Certificate;
use App\Core\Entity\User;
use App\Document\Enum\DocumentVersionKind;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
use App\Signing\Service\SigningRequestService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Renders every page of the app in its real auth state and saves the HTML to
 * docs/design/html/. Doubles as a smoke test (every page must return 200).
 * The snapshots feed the design brief in docs/design/DESIGN_BRIEF.md.
 */
final class PageSnapshotTest extends AuthWebTestCase
{
    private const OUT_DIR = '/docs/design/html';

    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testPublicPagesRenderAndAreSnapshotted(): void
    {
        foreach ([
            'login' => '/login',
            'register' => '/register',
            'reset_password_request' => '/reset-password',
            'resend_verification' => '/verify/resend',
        ] as $name => $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful(sprintf('%s must render', $path));
            $this->snapshot($name);
        }
    }

    public function testTwoFactorLoginPageRendersAndIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshot2fa');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->submitLogin($email, self::PASSWORD);

        $this->client->request('GET', '/2fa');
        self::assertResponseIsSuccessful();
        $this->snapshot('2fa_login');
    }

    public function testTwoFactorSetupPageRendersAndIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshotsetup');
        $this->createUser($email, verified: true, totpEnabled: false);
        $this->submitLogin($email, self::PASSWORD);

        $this->client->request('GET', '/2fa/setup');
        self::assertResponseIsSuccessful();
        $this->snapshot('2fa_setup');
    }

    public function testDashboardRendersAndIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshotdash');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->submitLogin($email, self::PASSWORD);

        $crawler = $this->client->request('GET', '/2fa');
        $form = $crawler->filter('form[action$="2fa_check"]')->form([
            '_auth_code' => $this->totpCode(self::TOTP_SECRET),
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        $this->snapshot('dashboard');
    }

    /**
     * The page an upload lands on. Snapshotted with a usable certificate so the
     * right column shows the live sign form rather than the empty state - that
     * column is what the upload-flow rework redesigns.
     */
    public function testPostUploadSignPageRendersAndIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshotsign');
        $user = $this->createUser($email, verified: true, totpEnabled: true);
        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($user, self::MINIMAL_PDF, 'Consultancy Agreement.pdf');
        $documentId = $document->getId()->toRfc4122();
        $this->makeCertificate($user);

        $this->loginFully($email);

        $this->client->request('GET', '/documents/'.$documentId.'/sign');
        self::assertResponseIsSuccessful();
        $this->snapshot('documents_sign_after_upload');
    }

    /** The library toolbar: search left, filter dropdowns right. */
    public function testDocumentsListIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshotlist');
        $user = $this->createUser($email, verified: true, totpEnabled: true);
        $uploader = static::getContainer()->get(DocumentUploader::class);
        foreach (['Consultancy Agreement.pdf', 'Board Resolution.pdf', 'NDA.pdf'] as $title) {
            $uploader->upload($user, self::MINIMAL_PDF, $title);
        }

        $this->loginFully($email);

        $this->client->request('GET', '/documents');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Search documents', $html);
        self::assertStringContainsString('data-pc-toggle="dropdown"', $html);
        $this->snapshot('documents_list');
    }

    /** The document page's action list: three rows, spent ones still present. */
    public function testDocumentPageIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshotdoc');
        $user = $this->createUser($email, verified: true, totpEnabled: true);
        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($user, self::MINIMAL_PDF, 'Consultancy Agreement.pdf');
        $documentId = $document->getId()->toRfc4122();
        $this->makeCertificate($user);

        $this->loginFully($email);

        $this->client->request('GET', '/documents/'.$documentId);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('What can still happen', $this->client->getResponse()->getContent() ?: '');
        $this->snapshot('document_show');
    }

    /** Once it is signed, self-signing reads as spent and delivery is the way on. */
    public function testSignedDocumentPageDropsThePurposeCard(): void
    {
        $email = $this->uniqueEmail('snapshotsigned');
        $user = $this->createUser($email, verified: true, totpEnabled: true);
        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($user, self::MINIMAL_PDF, 'Consultancy Agreement.pdf');
        $this->makeCertificate($user);
        static::getContainer()->get(DocumentVersionWriter::class)->write(
            $document,
            $user,
            '%PDF-ALREADY-SIGNED',
            DocumentVersionKind::Signed,
            'document.signed',
        );
        $documentId = $document->getId()->toRfc4122();

        $this->loginFully($email);

        $this->client->request('GET', '/documents/'.$documentId);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString("Decide what it's for", $html);
        // Signing yourself is spent for good, so its row is gone entirely.
        self::assertStringNotContainsString('Sign it yourself', $html);
        // The other two remain: a signed document can still be sent on or served.
        self::assertStringContainsString('Ask other people to sign', $html);
        self::assertStringContainsString('Deliver it', $html);
        $this->snapshot('document_show_signed');
    }

    /** The chooser when the user cannot sign: two options greyed, two open. */
    public function testPostUploadSignPageWithoutCertificateIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshotnocert');
        $user = $this->createUser($email, verified: true, totpEnabled: true);
        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($user, self::MINIMAL_PDF, 'Consultancy Agreement.pdf');
        $documentId = $document->getId()->toRfc4122();

        $this->loginFully($email);

        $this->client->request('GET', '/documents/'.$documentId.'/sign');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Needs a usable certificate', $this->client->getResponse()->getContent() ?: '');
        $this->snapshot('documents_sign_no_certificate');
    }

    /**
     * A document out for signature: the queue on Overview, and every action in
     * "What can still happen" spent at once - the state the action list exists
     * for.
     */
    public function testDocumentPageWithAnOpenRequestIsSnapshotted(): void
    {
        $ownerEmail = $this->uniqueEmail('snapshotpending-owner');
        $owner = $this->createUser($ownerEmail, verified: true, totpEnabled: true);
        $signer = $this->createUser($this->uniqueEmail('snapshotpending-signer'), verified: true, totpEnabled: true);
        $this->makeCertificate($signer);

        $document = static::getContainer()->get(DocumentUploader::class)
            ->upload($owner, self::MINIMAL_PDF, 'Framework contract.pdf');
        static::getContainer()->get(SigningRequestService::class)
            ->create($document, $owner, [$signer], new \DateTimeImmutable('+7 days'));

        $documentId = $document->getId()->toRfc4122();
        $this->loginFully($ownerEmail);

        $this->client->request('GET', '/documents/'.$documentId);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Signing order', $html);
        // Signing and delivery are blocked, not spent - they come back when the
        // request closes - so both rows stay and say when.
        self::assertStringContainsString('Signing is the queue', $html);
        self::assertStringContainsString('Not yet', $html);
        // The request itself is spent the moment it is sent: one per document.
        self::assertStringNotContainsString('Ask other people to sign', $html);
        $this->snapshot('document_show_pending');

        // History is the same request told as a timeline, and it exists from
        // the moment the request is sent.
        $this->client->request('GET', '/documents/'.$documentId.'?tab=history');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Request sent to 1 signer', (string) $this->client->getResponse()->getContent());
        $this->snapshot('document_show_history');

        $this->client->request('GET', '/documents/'.$documentId.'?tab=versions');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Integrity fingerprint', (string) $this->client->getResponse()->getContent());
        $this->snapshot('document_show_versions');
    }

    /** A renderable certificate - nothing here touches a real token. */
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
            pinHash: password_hash('123456', \PASSWORD_ARGON2ID),
        );
        $em->persist($certificate);
        $em->flush();

        return $certificate;
    }

    private function snapshot(string $name): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . self::OUT_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir . '/' . $name . '.html', $this->client->getResponse()->getContent());
    }
}
