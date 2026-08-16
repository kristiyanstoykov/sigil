<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Certificate\Entity\Certificate;
use App\Core\Entity\User;
use App\Delivery\Service\DeliveryService;
use App\Document\Service\DocumentUploader;
use App\Signing\Service\SigningRequestService;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The dashboard is the one page that mixes roles, and every number on it is a
 * real query - these assert that, so the fixtures it used to ship cannot come back.
 */
final class DashboardTest extends AuthWebTestCase
{
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testAFreshAccountGetsOnboardingRatherThanEmptyTiles(): void
    {
        $email = $this->uniqueEmail('dash-new');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->loginFully($email);

        $html = $this->client->request('GET', '/')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString("Let's get you ready to sign", $html);
        self::assertStringNotContainsString('Needs your action', $html);
    }

    public function testEveryTileAndListComesFromRealData(): void
    {
        $meEmail = $this->uniqueEmail('dash-me');
        $senderEmail = $this->uniqueEmail('dash-sender');
        $me = $this->createUser($meEmail, verified: true, totpEnabled: true);
        $sender = $this->createUser($senderEmail, verified: true, totpEnabled: true);
        $this->giveCertificate($me);

        $uploader = static::getContainer()->get(DocumentUploader::class);

        // Someone asks me to sign: one turn that is mine.
        $incoming = $uploader->upload($sender, self::MINIMAL_PDF, 'Series-B SAFE.pdf');
        static::getContainer()->get(SigningRequestService::class)
            ->create($incoming, $sender, [$me], (new \DateTimeImmutable())->modify('+3 days'));

        // Someone serves me a document: delivered, and nothing to do about it.
        $served = $uploader->upload($sender, self::MINIMAL_PDF, 'Policy update.pdf');
        static::getContainer()->get(DeliveryService::class)->deliver($served, $sender, [$me]);

        // And I own one of my own.
        $uploader->upload($me, self::MINIMAL_PDF, 'Q3 report.pdf');

        $this->loginFully($meEmail);
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        $html = $crawler->html();

        // The tiles, and the lists behind them.
        self::assertStringContainsString('Waiting on you', $html);
        self::assertStringContainsString('is waiting for your signature', $html);
        self::assertStringContainsString('Series-B SAFE.pdf', $html);
        self::assertStringContainsString('Delivered to you', $html);

        // Real documents, not the fixtures this page used to carry.
        self::assertStringNotContainsString('Payroll addendum.pdf', $html);
        self::assertStringNotContainsString('Apple Inc.', $html);

        // The activity feed reads the audit log, so uploading shows up there.
        self::assertStringContainsString('Uploaded a document', $html);
        self::assertStringContainsString('Your activity · last 6 months', $html);
    }

    private function giveCertificate(User $user): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $em->persist(new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Dashboard Signer',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'dash-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash('135790', \PASSWORD_ARGON2ID),
        ));
        $em->flush();
    }
}
