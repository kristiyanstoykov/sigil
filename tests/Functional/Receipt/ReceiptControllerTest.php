<?php

declare(strict_types=1);

namespace App\Tests\Functional\Receipt;

use App\Certificate\Entity\Certificate;
use App\Core\Entity\User;
use App\Core\Repository\UserRepository;
use App\Document\Service\DocumentUploader;
use App\Receipt\Entity\DeliveryReceipt;
use App\Receipt\Repository\DeliveryReceiptRepository;
use App\Receipt\Service\ReceiptSealer;
use App\Signing\Service\SigningRequestService;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The receipts pages: a participant sees and downloads their receipt, anyone
 * else gets a 404 - the grants are the access list, exactly as for documents.
 */
class ReceiptControllerTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::getContainer()->get(ReceiptSealer::class)->isReady()) {
            self::markTestSkipped('Run sigil:ca:init and sigil:seal:init first.');
        }
    }

    public function testEmptyStateThenTheSealedReceiptAppearsAndDownloads(): void
    {
        $ownerEmail = $this->uniqueEmail('owner');
        $this->createUser($ownerEmail, verified: true, totpEnabled: true);
        $this->loginFully($ownerEmail);

        $crawler = $this->client->request('GET', '/receipts');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('No receipts yet', $crawler->text());

        $receipt = $this->sealReceipt($ownerEmail);

        $crawler = $this->client->request('GET', '/receipts');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Contract.pdf', $crawler->text());

        $this->client->request('GET', '/receipts/'.$receipt->getId()->toRfc4122().'/download');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    public function testSomeoneWhoWasNotAParticipantCannotDownloadIt(): void
    {
        $ownerEmail = $this->uniqueEmail('owner');
        $this->createUser($ownerEmail, verified: true, totpEnabled: true);
        $this->loginFully($ownerEmail);
        $receipt = $this->sealReceipt($ownerEmail);

        $strangerEmail = $this->uniqueEmail('stranger');
        $this->createUser($strangerEmail, verified: true, totpEnabled: true);
        $this->client->getCookieJar()->clear(); // drop the owner's session first
        $this->loginFully($strangerEmail);

        $this->client->request('GET', '/receipts/'.$receipt->getId()->toRfc4122().'/download');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $crawler = $this->client->request('GET', '/receipts');
        self::assertStringContainsString('No receipts yet', $crawler->text(), 'a receipt only lists for its participants');
    }

    public function testAMalformedIdIsNotFound(): void
    {
        $email = $this->uniqueEmail('owner');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->loginFully($email);

        $this->client->request('GET', '/receipts/not-a-uuid/download');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** Upload, send a one-signer request, withdraw it - the close seals the receipt. */
    private function sealReceipt(string $ownerEmail): DeliveryReceipt
    {
        $signer = $this->createUser($this->uniqueEmail('signer'));
        $this->makeCertificate($signer);

        $container = static::getContainer();
        // Re-read the owner: every HTTP request above rebooted the kernel, so a
        // User captured before them belongs to a closed entity manager.
        $owner = $container->get(UserRepository::class)->findOneByEmail($ownerEmail);
        self::assertNotNull($owner);
        $document = $container->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, 'Contract.pdf');
        $request = $container->get(SigningRequestService::class)->create(
            $document,
            $owner,
            [$signer],
            (new \DateTimeImmutable())->modify('+7 days'),
        );
        $container->get(SigningRequestService::class)->cancel($request, $owner);

        $receipt = $container->get(DeliveryReceiptRepository::class)->findForRequest($request->getId());
        self::assertNotNull($receipt);

        return $receipt;
    }

    private function makeCertificate(User $user): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $em->persist(new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Test Signer',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'test-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash('135790', \PASSWORD_ARGON2ID),
        ));
        $em->flush();
    }
}
