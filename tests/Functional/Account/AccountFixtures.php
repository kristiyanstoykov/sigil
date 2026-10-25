<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Entity\Certificate;
use App\Certificate\Service\PinGate;
use App\Certificate\Service\SuiteCredentials;
use App\Core\Entity\User;
use App\Document\Entity\Document;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\DocumentSigner;
use App\Signing\Service\NoTsaProvider;
use App\Signing\Service\PadesSignerInterface;
use App\Signing\Service\PadesSignRequest;
use App\Signing\Service\SigningRequestService;
use App\Signing\Service\TsaProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/** Documents, certificates and a fake-token signer for the account tests. */
trait AccountFixtures
{
    private const PIN = '135790';
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function get(string $id): object
    {
        /** @var T */
        return static::getContainer()->get($id);
    }

    private function upload(User $owner): Document
    {
        return $this->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, 'Contract.pdf');
    }

    private function inDays(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d days', $days));
    }

    private function signerUser(string $role): User
    {
        $user = $this->createUser($this->uniqueEmail($role));
        $this->makeCertificate($user);

        return $user;
    }

    private function certificateOf(User $user): Certificate
    {
        $certificate = $this->get(EntityManagerInterface::class)->getRepository(Certificate::class)->findOneBy(['user' => $user]);
        self::assertNotNull($certificate);

        return $certificate;
    }

    private function signer(): DocumentSigner
    {
        $c = static::getContainer();
        $caDir = sys_get_temp_dir().'/sigil-account-test-ca';
        @mkdir($caDir);
        file_put_contents($caDir.'/ca.crt', "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");

        $fake = new class implements PadesSignerInterface {
            public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
            {
                return $request->pdfBytes."\n% signed";
            }
        };

        return new DocumentSigner(
            $c->get(PinGate::class),
            $c->get(DocumentDownloader::class),
            $fake,
            new TsaProviderRegistry([new NoTsaProvider()], 'none'),
            $c->get(DocumentVersionWriter::class),
            $c->get(SigningRequestRepository::class),
            $c->get(SigningRequestService::class),
            $c->get(EventDispatcherInterface::class),
            $c->get(EntityManagerInterface::class),
            $c->get(SignatureAlgorithmRegistry::class),
            new SuiteCredentials($caDir),
        );
    }

    private function makeCertificate(User $user): Certificate
    {
        $em = $this->get(EntityManagerInterface::class);
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
}
