<?php

declare(strict_types=1);

namespace App\Tests\Functional\Mailer;

use App\Certificate\Entity\Certificate;
use App\Certificate\Repository\CertificateRepository;
use App\Certificate\Service\PinGate;
use App\Core\Entity\User;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentSharer;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\DocumentSigner;
use App\Signing\Service\NoTsaProvider;
use App\Signing\Service\PadesSignerInterface;
use App\Signing\Service\PadesSignRequest;
use App\Signing\Service\SigningRequestNotifier;
use App\Signing\Service\SigningRequestService;
use App\Signing\Service\TsaProviderRegistry;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;

/**
 * The notification emails: who gets told what, and that every template actually
 * renders (the null transport still builds the message body).
 */
final class NotificationTest extends AuthWebTestCase
{
    use MailerAssertionsTrait;

    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testUploadingTellsTheOwner(): void
    {
        $owner = $this->createUser($this->uniqueEmail('mail-upload'));

        static::getContainer()->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, 'Statement.pdf');

        self::assertEmailCount(1);
        $email = $this->messageTo($owner->getEmail());
        self::assertSame('Uploaded to Sigil: Statement.pdf', $email->getSubject());
        self::assertStringContainsString('encrypted at rest', (string) $email->getHtmlBody());
    }

    public function testSharingTellsTheRecipient(): void
    {
        $owner = $this->createUser($this->uniqueEmail('mail-share-owner'));
        $recipient = $this->createUser($this->uniqueEmail('mail-share-recipient'));
        $document = static::getContainer()->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, 'Policy.pdf');

        static::getContainer()->get(DocumentSharer::class)->share($document, $owner, $recipient);

        // Two messages: the upload confirmation to the owner, the share to them.
        self::assertEmailCount(2);
        $email = $this->messageTo($recipient->getEmail());
        self::assertStringContainsString('shared', (string) $email->getSubject());
    }

    public function testARequestInvitesOnlyTheFirstSigner(): void
    {
        $owner = $this->createUser($this->uniqueEmail('mail-req-owner'));
        $first = $this->withCertificate($this->createUser($this->uniqueEmail('mail-req-first')));
        $second = $this->withCertificate($this->createUser($this->uniqueEmail('mail-req-second')));
        $document = static::getContainer()->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, 'Contract.pdf');

        static::getContainer()->get(SigningRequestService::class)
            ->create($document, $owner, [$first, $second], new \DateTimeImmutable('+7 days'));

        // Upload + exactly one invitation: the second signer hears nothing yet.
        self::assertEmailCount(2);
        $email = $this->messageTo($first->getEmail());
        self::assertSame('Your signature is requested: Contract.pdf', $email->getSubject());
        self::assertStringContainsString('You are signer', (string) $email->getHtmlBody());
        self::assertStringContainsString('/sign', (string) $email->getHtmlBody());
    }

    public function testASignatureBySomeoneElseTellsTheOwner(): void
    {
        $owner = $this->createUser($this->uniqueEmail('mail-signed-owner'));
        $signer = $this->withCertificate($this->createUser($this->uniqueEmail('mail-signed-signer')));
        $document = static::getContainer()->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, 'Deed.pdf');

        $container = static::getContainer();
        $container->get(SigningRequestService::class)
            ->create($document, $owner, [$signer], new \DateTimeImmutable('+7 days'));

        $caPath = sys_get_temp_dir().'/sigil-mail-test-ca.crt';
        file_put_contents($caPath, "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");
        $fake = new class implements PadesSignerInterface {
            public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
            {
                return $request->pdfBytes."\n% signed";
            }
        };

        (new DocumentSigner(
            $container->get(PinGate::class),
            $container->get(DocumentDownloader::class),
            $fake,
            new TsaProviderRegistry([new NoTsaProvider()], 'none'),
            $container->get(DocumentVersionWriter::class),
            $container->get(SigningRequestRepository::class),
            $container->get(SigningRequestService::class),
            $container->get(SigningRequestNotifier::class),
            $caPath,
        ))->sign($document, $this->certificateOf($signer), $signer, '135790');

        $email = $this->messageTo($owner->getEmail());
        self::assertStringContainsString('signed', (string) $email->getSubject());
    }

    private function certificateOf(User $user): Certificate
    {
        return static::getContainer()->get(CertificateRepository::class)->findByUser($user)[0];
    }

    /**
     * The most recent collected message addressed to $address. Most flows also
     * mail the owner earlier (the upload confirmation), so the newest one is the
     * one the test is about.
     */
    private function messageTo(string $address): \Symfony\Component\Mime\Email
    {
        foreach (array_reverse(self::getMailerMessages()) as $message) {
            \assert($message instanceof \Symfony\Component\Mime\Email);
            foreach ($message->getTo() as $to) {
                if ($to->getAddress() === $address) {
                    return $message;
                }
            }
        }

        self::fail(sprintf('No email was sent to %s.', $address));
    }

    private function withCertificate(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $em->persist(new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Mail Signer',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'mail-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash('135790', \PASSWORD_ARGON2ID),
        ));
        $em->flush();

        return $user;
    }
}
