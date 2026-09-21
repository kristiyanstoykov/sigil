<?php

declare(strict_types=1);

namespace App\Tests\Functional\Signing;

use App\AuditLog\AuditLoggerInterface;
use App\AuditLog\Entity\AuditLogEntry;
use App\AuditLog\Enum\AuditSeverity;
use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Entity\Certificate;
use App\Certificate\Service\PinGate;
use App\Certificate\Service\SuiteCredentials;
use App\Core\Entity\User;
use App\Document\Enum\DocumentVersionKind;
use App\Core\Crypto\EncryptionServiceInterface;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\ContentHasher;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentStorageInterface;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
use App\Document\Service\KeyManagementService;
use App\Signing\Exception\TokenPinRejectedException;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\DocumentSigner;
use App\Signing\Service\NoTsaProvider;
use App\Signing\Service\PadesSignerInterface;
use App\Signing\Service\PadesSignRequest;
use Psr\EventDispatcher\EventDispatcherInterface;
use App\Signing\Service\SigningRequestService;
use App\Signing\Service\TsaProviderRegistry;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;

/**
 * DocumentSigner over the REAL collaborators (PinGate, downloader, version
 * writer, TSA registry) but a FAKE {@see PadesSignerInterface}, so both the
 * happy path and the ADR-008 desync branch are exercised deterministically
 * without a PKCS#11 token. The real-token, real-PAdES path is covered by the
 * end-to-end signing test.
 */
class DocumentSignerTest extends AuthWebTestCase
{
    private const PIN = '135790';
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

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

    private function signerWith(PadesSignerInterface $fake): DocumentSigner
    {
        $c = static::getContainer();

        // Real collaborators, fake signer, a throwaway CA file the fake ignores.
        // The fake signer ignores the chain, but DocumentSigner insists the CA
        // file exists: a throwaway var/ca with a placeholder for the classical suite.
        $caDir = sys_get_temp_dir().'/sigil-signer-test-ca';
        @mkdir($caDir);
        file_put_contents($caDir.'/ca.crt', "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");

        return new DocumentSigner(
            $c->get(PinGate::class),
            $c->get(DocumentDownloader::class),
            $fake,
            // Built locally: the registry is inlined away until a controller
            // references DocumentSigner. Test env uses the "none" TSA anyway.
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

    public function testSignStoresSignedVersionAndPassesAWellFormedRequest(): void
    {
        $user = $this->createUser($this->uniqueEmail('sign'));
        $document = static::getContainer()->get(DocumentUploader::class)->upload($user, self::MINIMAL_PDF, 'Contract.pdf');
        $certificate = $this->makeCertificate($user);

        $fake = new class('%PDF-SIGNED-BYTES') implements PadesSignerInterface {
            public ?PadesSignRequest $seen = null;

            public function __construct(public readonly string $out)
            {
            }

            public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
            {
                $this->seen = $request;

                return $this->out;
            }
        };

        $signedVersion = $this->signerWith($fake)->sign($document, $certificate, $user, self::PIN);

        // A second, Signed version now exists and decrypts to the signer's output.
        self::assertSame(DocumentVersionKind::Signed, $signedVersion->getKind());
        self::assertSame(2, $signedVersion->getVersionNumber());
        self::assertCount(2, $document->getVersions());
        self::assertSame($fake->out, static::getContainer()->get(DocumentDownloader::class)->download($signedVersion, $user));

        // The original is untouched (still the uploaded bytes).
        $original = $document->getVersions()->first();
        self::assertNotFalse($original);
        self::assertSame(DocumentVersionKind::Original, $original->getKind());
        self::assertSame(self::MINIMAL_PDF, static::getContainer()->get(DocumentDownloader::class)->download($original, $user));

        // The request the signer received is well-formed.
        self::assertNotNull($fake->seen);
        self::assertSame(self::MINIMAL_PDF, $fake->seen->pdfBytes);
        self::assertSame($certificate->getTokenLabel(), $fake->seen->tokenLabel);
        self::assertSame('TEST SIGNER', $fake->seen->signerName);
        // Sigil-namespaced, version-tagged, random suffix (collision-proof on
        // already-signed PDFs). Never a bare "SignatureN".
        self::assertMatchesRegularExpression('/^SigilSignature-v2-[0-9a-f]{8}$/', $fake->seen->fieldName);
        self::assertNull($fake->seen->tsaUrl); // .env.test => SIGIL_TSA_ACTIVE_BACKEND=none
    }

    /**
     * The token has signed, the ciphertext is stored, and then the database
     * step fails: nothing may be left behind - no version row, no grant, and no
     * orphaned ciphertext in the object store.
     */
    public function testADatabaseFailureAfterStoringTheCiphertextLeavesNothingBehind(): void
    {
        $c = static::getContainer();
        $user = $this->createUser($this->uniqueEmail('rollback'));
        $document = $c->get(DocumentUploader::class)->upload($user, self::MINIMAL_PDF, 'Contract.pdf');
        $documentId = $document->getId()->toRfc4122();
        $certificate = $this->makeCertificate($user);

        $storage = new class($c->get(DocumentStorageInterface::class)) implements DocumentStorageInterface {
            /** @var list<string> */
            public array $stored = [];

            public function __construct(private readonly DocumentStorageInterface $inner)
            {
            }

            public function store(string $ciphertext): string
            {
                return $this->stored[] = $this->inner->store($ciphertext);
            }

            public function retrieve(string $storageKey): string
            {
                return $this->inner->retrieve($storageKey);
            }

            public function delete(string $storageKey): void
            {
                $this->inner->delete($storageKey);
            }

            public function exists(string $storageKey): bool
            {
                return $this->inner->exists($storageKey);
            }
        };

        // The audit entry for the signed version is the last DB step of the
        // write; failing it is the failure the transaction has to absorb.
        $failingAudit = new class implements AuditLoggerInterface {
            public function log(string $action, ?User $actor = null, array $payload = [], ?string $subjectType = null, ?string $subjectId = null, AuditSeverity $severity = AuditSeverity::Info): AuditLogEntry
            {
                throw new \RuntimeException('audit store unavailable');
            }
        };

        $writer = new DocumentVersionWriter(
            $c->get(EncryptionServiceInterface::class),
            $c->get(ContentHasher::class),
            $c->get(KeyManagementService::class),
            $storage,
            $c->get(DocumentKeyGrantRepository::class),
            $failingAudit,
            $c->get(EntityManagerInterface::class),
            new NullLogger(),
        );
        // The fake signer ignores the chain, but DocumentSigner insists the CA
        // file exists: a throwaway var/ca with a placeholder for the classical suite.
        $caDir = sys_get_temp_dir().'/sigil-signer-test-ca';
        @mkdir($caDir);
        file_put_contents($caDir.'/ca.crt', "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");
        $signer = new DocumentSigner(
            $c->get(PinGate::class),
            $c->get(DocumentDownloader::class),
            new class implements PadesSignerInterface {
                public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
                {
                    return '%PDF-SIGNED-BYTES';
                }
            },
            new TsaProviderRegistry([new NoTsaProvider()], 'none'),
            $writer,
            $c->get(SigningRequestRepository::class),
            $c->get(SigningRequestService::class),
            $c->get(EventDispatcherInterface::class),
            $c->get(EntityManagerInterface::class),
            $c->get(SignatureAlgorithmRegistry::class),
            new SuiteCredentials($caDir),
        );

        try {
            $signer->sign($document, $certificate, $user, self::PIN);
            self::fail('the failure must surface');
        } catch (\RuntimeException $e) {
            self::assertSame('audit store unavailable', $e->getMessage());
        }

        self::assertCount(1, $storage->stored, 'the signed ciphertext was stored before the failure');
        self::assertFalse($storage->exists($storage->stored[0]), 'and taken back after it');

        $c->get('doctrine')->resetManager();
        $reloaded = $c->get(DocumentRepository::class)->find($documentId);
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getVersions(), 'only the original remains');
    }

    public function testTokenPinRejectionLocksTheCertificateAndAddsNoVersion(): void
    {
        $user = $this->createUser($this->uniqueEmail('desync'));
        $document = static::getContainer()->get(DocumentUploader::class)->upload($user, self::MINIMAL_PDF, 'Contract.pdf');
        $certificate = $this->makeCertificate($user);

        $fake = new class implements PadesSignerInterface {
            public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
            {
                throw new TokenPinRejectedException('The token rejected the PIN.');
            }
        };

        try {
            $this->signerWith($fake)->sign($document, $certificate, $user, self::PIN);
            self::fail('Expected TokenPinRejectedException.');
        } catch (TokenPinRejectedException) {
            // expected
        }

        // Desync tripwire: the certificate is now locked, and no Signed version was added.
        self::assertTrue($certificate->isLocked());
        self::assertCount(1, $document->getVersions());
    }

    public function testASignedDocumentCannotBeSignedAgain(): void
    {
        $user = $this->createUser($this->uniqueEmail('signonce'));
        $document = static::getContainer()->get(DocumentUploader::class)->upload($user, self::MINIMAL_PDF, 'Contract.pdf');
        $certificate = $this->makeCertificate($user);

        $fake = new class implements PadesSignerInterface {
            public int $calls = 0;

            public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
            {
                ++$this->calls;

                return '%PDF-SIGNED-BYTES';
            }
        };

        $signer = $this->signerWith($fake);
        $signer->sign($document, $certificate, $user, self::PIN);

        try {
            $signer->sign($document, $certificate, $user, self::PIN);
            self::fail('Expected the second signature to be refused.');
        } catch (\App\Core\Exception\DomainException $e) {
            self::assertStringContainsString('already been signed', $e->getMessage());
        }

        // The guard sits above the token and above the version writer: exactly
        // one signature was produced and no third version exists.
        self::assertSame(1, $fake->calls);
        self::assertCount(2, $document->getVersions());
    }

    public function testWrongPinIsRejectedBeforeSigning(): void
    {
        $user = $this->createUser($this->uniqueEmail('badpin'));
        $document = static::getContainer()->get(DocumentUploader::class)->upload($user, self::MINIMAL_PDF, 'Contract.pdf');
        $certificate = $this->makeCertificate($user);

        $fake = new class implements PadesSignerInterface {
            public bool $called = false;

            public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
            {
                $this->called = true;

                return '';
            }
        };

        $this->expectException(\App\Certificate\Exception\InvalidPinException::class);
        try {
            $this->signerWith($fake)->sign($document, $certificate, $user, '000000');
        } finally {
            self::assertFalse($fake->called, 'The token must never be reached on a wrong PIN.');
            self::assertCount(1, $document->getVersions());
        }
    }
}
