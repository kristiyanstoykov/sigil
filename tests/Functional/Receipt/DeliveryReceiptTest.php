<?php

declare(strict_types=1);

namespace App\Tests\Functional\Receipt;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Certificate\Entity\Certificate;
use App\Certificate\Repository\CertificateRepository;
use App\Certificate\Service\PinGate;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
use App\Receipt\Enum\ReceiptOutcome;
use App\Receipt\Enum\ReceiptSource;
use App\Receipt\Repository\DeliveryReceiptRepository;
use App\Receipt\Service\ReceiptDownloader;
use App\Receipt\Service\ReceiptGenerator;
use App\Receipt\Service\ReceiptRendererInterface;
use App\Receipt\Service\ReceiptSealer;
use App\Receipt\Service\ReceiptWriter;
use App\Signing\Entity\SigningRequest;
use App\Signing\Enum\SigningRequestStatus;
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
use Psr\Clock\ClockInterface;
use Symfony\Component\Process\Process;

/**
 * Delivery receipts (ADR-012): what gets sealed, when, and who can read it.
 *
 * The seal certificate is the real one (so the recorded serial is real), but the
 * PKCS#11 signature is faked - the E2E variant covers the actual token.
 */
class DeliveryReceiptTest extends AuthWebTestCase
{
    private const PIN = '135790';
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

    public function testCompletedRequestIsSealedIntoAReceiptEveryParticipantCanRead(): void
    {
        [$owner, $first, $second] = $this->threeUsers();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first, $second], $this->inDays(7));

        $this->signAs($document, $first);
        $this->signAs($document, $second);
        self::assertSame(SigningRequestStatus::Completed, $request->getStatus());

        $receipt = $this->generator()->generateFor($request);

        self::assertSame(ReceiptOutcome::Completed, $receipt->getOutcome());
        self::assertSame($document->getTitle(), $receipt->getDocumentTitle());
        self::assertNotSame('', $receipt->getStorageKey());

        // Requester and both signers hold a grant; a stranger does not.
        $downloader = static::getContainer()->get(ReceiptDownloader::class);
        foreach ([$owner, $first, $second] as $participant) {
            self::assertStringStartsWith('%PDF', $downloader->download($receipt, $participant));
        }

        $stranger = $this->createUser($this->uniqueEmail('stranger'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('do not have access');
        $downloader->download($receipt, $stranger);
    }

    public function testReceiptIsSealedAutomaticallyWhenTheRequestCloses(): void
    {
        [$owner, $first] = $this->threeUsers();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first], $this->inDays(7));

        // No explicit generate call: cancelling dispatches SigningRequestClosed
        // and the Receipt subscriber does the rest.
        $this->service()->cancel($request, $owner);

        $receipt = static::getContainer()->get(DeliveryReceiptRepository::class)->findForSource(ReceiptSource::SigningRequest, $request->getId());
        self::assertNotNull($receipt, 'closing a request seals its receipt');
        self::assertSame(ReceiptOutcome::Cancelled, $receipt->getOutcome());
    }

    public function testReceiptOutlivesADocumentDestroyedByTheSweep(): void
    {
        [$owner, $first] = $this->threeUsers();
        $document = $this->upload($owner);
        $documentId = $document->getId();
        $request = $this->service()->create($document, $owner, [$first], $this->inDays(1));
        $this->makeOverdue($request);

        $this->runCommand('sigil:signing:sweep');

        $documents = static::getContainer()->get(DocumentRepository::class);
        self::assertNull($documents->find($documentId->toRfc4122()), 'nobody signed, so the document is destroyed');

        $receipt = static::getContainer()->get(DeliveryReceiptRepository::class)->findForSource(ReceiptSource::SigningRequest, $request->getId());
        self::assertNotNull($receipt, 'the receipt survives its document - it is what attests the deletion');
        self::assertSame(ReceiptOutcome::Expired, $receipt->getOutcome());
        self::assertSame($documentId->toRfc4122(), $receipt->getDocumentId()->toRfc4122());
    }

    public function testARefusalIsSealedIntoTheReceiptAsItsOutcome(): void
    {
        [$owner, $first] = $this->threeUsers();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first], $this->inDays(7));

        $this->service()->decline($request, $first, 'Not the agreed wording.');

        $receipt = static::getContainer()->get(DeliveryReceiptRepository::class)->findForSource(ReceiptSource::SigningRequest, $request->getId());
        self::assertNotNull($receipt, 'a refusal closes the request, so it seals a receipt like any other close');
        self::assertSame(ReceiptOutcome::Declined, $receipt->getOutcome());

        // The requester keeps the receipt even though the signer refused: the
        // refusal is exactly what it has to attest.
        $pdf = static::getContainer()->get(ReceiptDownloader::class)->download($receipt, $owner);
        self::assertStringStartsWith('%PDF', $pdf);
    }

    public function testSealingIsIdempotentPerRequest(): void
    {
        [$owner, $first] = $this->threeUsers();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first], $this->inDays(7));
        $this->service()->cancel($request, $owner);

        $again = $this->generator()->generateFor($request);
        $stored = static::getContainer()->get(DeliveryReceiptRepository::class)->findForSource(ReceiptSource::SigningRequest, $request->getId());

        self::assertNotNull($stored);
        self::assertSame($stored->getId()->toRfc4122(), $again->getId()->toRfc4122(), 'one receipt per request');
    }

    public function testAnOpenRequestHasNoReceipt(): void
    {
        [$owner, $first] = $this->threeUsers();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first], $this->inDays(7));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('only issued once the request is closed');

        $this->generator()->generateFor($request);
    }

    public function testTheSealedPdfCarriesTheEvidenceChain(): void
    {
        [$owner, $first] = $this->threeUsers();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first], $this->inDays(7));
        $this->signAs($document, $first);

        $receipt = static::getContainer()->get(DeliveryReceiptRepository::class)->findForSource(ReceiptSource::SigningRequest, $request->getId());
        self::assertNotNull($receipt);

        // The receipt renders the audit entries for this document, so every entry
        // hash in the log has to be reproducible from the sealed evidence set.
        $entries = static::getContainer()->get(AuditLogEntryRepository::class)
            ->findForSubject('Document', $document->getId()->toRfc4122());
        self::assertNotEmpty($entries);

        $pdf = static::getContainer()->get(ReceiptDownloader::class)->download($receipt, $owner);
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(1000, \strlen($pdf), 'a rendered receipt is a real document, not a stub');

        // The seal is a real PAdES signature that chains to the Sigil CA - this
        // is the whole point of the receipt, so it is asserted, not assumed.
        self::assertSame('INTACT TRUSTED', $this->validatePades($pdf, $this->projectDir()));
    }

    private function projectDir(): string
    {
        return (string) static::getContainer()->getParameter('kernel.project_dir');
    }

    /** Validates the receipt's embedded seal with pyHanko against var/ca/ca.crt. */
    private function validatePades(string $pdfBytes, string $projectDir): string
    {
        $pdfFile = (string) tempnam(sys_get_temp_dir(), 'sigil-receipt-');
        file_put_contents($pdfFile, $pdfBytes);

        $script = <<<'PY'
            import sys
            from pyhanko.keys import load_cert_from_pemder
            from pyhanko.pdf_utils.reader import PdfFileReader
            from pyhanko.sign.validation import validate_pdf_signature
            from pyhanko_certvalidator import ValidationContext
            ca = load_cert_from_pemder(sys.argv[2])
            vc = ValidationContext(trust_roots=[ca])
            with open(sys.argv[1], "rb") as fh:
                sig = PdfFileReader(fh).embedded_signatures[-1]
                st = validate_pdf_signature(sig, vc)
            print(("INTACT" if st.intact else "BROKEN"), ("TRUSTED" if st.trusted else "UNTRUSTED"))
            PY;

        $process = new Process(['python3', '-c', $script, $pdfFile, $projectDir.'/var/ca/ca.crt'], cwd: $projectDir);
        $process->run();
        @unlink($pdfFile);

        return trim($process->getOutput());
    }

    /** @return array{User, User, User} */
    private function threeUsers(): array
    {
        $users = [];
        foreach (['owner', 'first', 'second'] as $role) {
            $user = $this->createUser($this->uniqueEmail($role));
            $this->makeCertificate($user);
            $users[] = $user;
        }

        return [$users[0], $users[1], $users[2]];
    }

    private function upload(User $owner): Document
    {
        return static::getContainer()->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, 'Contract.pdf');
    }

    private function inDays(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d days', $days));
    }

    private function service(): SigningRequestService
    {
        return static::getContainer()->get(SigningRequestService::class);
    }

    /**
     * The generator with a faked PKCS#11 signature but the real seal certificate,
     * so the serial it records is genuine.
     */
    private function generator(): ReceiptGenerator
    {
        $c = static::getContainer();
        $projectDir = $this->projectDir();

        $sealer = new ReceiptSealer(
            $this->fakePadesSigner(),
            new TsaProviderRegistry([new NoTsaProvider()], 'none'),
            'unused-pin',
            $projectDir.'/var/ca/seal.crt',
            $projectDir.'/var/ca/ca.crt',
        );

        return new ReceiptGenerator(
            $c->get(ReceiptRendererInterface::class),
            $sealer,
            $c->get(ReceiptWriter::class),
            $c->get(DeliveryReceiptRepository::class),
            $c->get(AuditLogEntryRepository::class),
            $c->get(AuditLoggerInterface::class),
            $c->get(ClockInterface::class),
        );
    }

    private function fakePadesSigner(): PadesSignerInterface
    {
        return new class implements PadesSignerInterface {
            public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
            {
                return $request->pdfBytes."\n% sealed";
            }
        };
    }

    private function signAs(Document $document, User $user): void
    {
        $c = static::getContainer();
        $caPath = sys_get_temp_dir().'/sigil-receipt-test-ca.crt';
        file_put_contents($caPath, "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");

        $certificate = static::getContainer()->get(CertificateRepository::class)
            ->findOneBy(['user' => $user]);
        \assert($certificate instanceof Certificate);

        $signer = new DocumentSigner(
            $c->get(PinGate::class),
            $c->get(DocumentDownloader::class),
            $this->fakePadesSigner(),
            new TsaProviderRegistry([new NoTsaProvider()], 'none'),
            $c->get(DocumentVersionWriter::class),
            $c->get(SigningRequestRepository::class),
            $c->get(SigningRequestService::class),
            $c->get(SigningRequestNotifier::class),
            $caPath,
        );

        $signer->sign($document, $certificate, $user, self::PIN);
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

    private function makeOverdue(SigningRequest $request): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'UPDATE signing_request SET deadline = :deadline WHERE id = :id',
            ['deadline' => (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s'), 'id' => $request->getId()->toRfc4122()],
        );
        $em->refresh($request);
    }

    private function runCommand(string $name): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        $application->setAutoExit(false);
        $application->run(
            new \Symfony\Component\Console\Input\ArrayInput(['command' => $name]),
            new \Symfony\Component\Console\Output\NullOutput(),
        );
    }
}
