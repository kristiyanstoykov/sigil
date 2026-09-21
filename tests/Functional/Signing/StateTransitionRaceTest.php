<?php

declare(strict_types=1);

namespace App\Tests\Functional\Signing;

use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Entity\Certificate;
use App\Certificate\Exception\CertificateLockedException;
use App\Certificate\Service\PinGate;
use App\Certificate\Service\SuiteCredentials;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Delivery\Service\DeliveryService;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentStorageInterface;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
use App\Signing\Entity\SigningRequest;
use App\Signing\Enum\SigningRequestStatus;
use App\Signing\Repository\SigningRequestRepository;
use App\Signing\Service\DocumentSigner;
use App\Signing\Service\NoTsaProvider;
use App\Signing\Service\PadesSignerInterface;
use App\Signing\Service\PadesSignRequest;
use App\Signing\Service\SigningRequestService;
use App\Signing\Service\TsaProviderRegistry;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Two requests race on one document: each passes its guard on the entity it
 * loaded, then the first to commit changes the world. The second must re-read
 * under a row lock inside its transaction and refuse - never "last writer
 * wins". The concurrent commit is played by raw SQL behind a stale entity,
 * which is exactly the shape the race takes.
 */
final class StateTransitionRaceTest extends AuthWebTestCase
{
    private const PIN = '135790';
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testASendCannotOvertakeADeliveryThatCommittedMeanwhile(): void
    {
        [$owner, $first] = $this->users();
        $document = $this->upload($owner);
        self::assertFalse($document->isDelivered(), 'the stale entity says undelivered');

        $this->sql('UPDATE document SET delivered_at = NOW() WHERE id = :id', $document);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('has been delivered');
        $this->requests()->create($document, $owner, [$first], $this->inDays(7));
    }

    public function testADeliveryCannotOvertakeASendThatCommittedMeanwhile(): void
    {
        [$owner, $first] = $this->users();
        $document = $this->upload($owner);

        $this->sql('UPDATE document SET awaiting_signatures_since = NOW() WHERE id = :id', $document);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('out for signature');
        static::getContainer()->get(DeliveryService::class)->deliver($document, $owner, [$first]);
    }

    public function testARefusalCannotReopenARequestWithdrawnMeanwhile(): void
    {
        [$owner, $first] = $this->users();
        $document = $this->upload($owner);
        $request = $this->requests()->create($document, $owner, [$first], $this->inDays(7));
        self::assertTrue($request->isPending(), 'the stale entity says pending');

        $this->sql("UPDATE signing_request SET status = 'cancelled', closed_at = NOW() WHERE id = :id", $request);

        try {
            $this->requests()->decline($request, $first, 'Too late.');
            self::fail('a closed request must not be closed again');
        } catch (DomainException $e) {
            self::assertStringContainsString('already closed', $e->getMessage());
        }
        static::getContainer()->get('doctrine')->resetManager();
        $reloaded = static::getContainer()->get(SigningRequestRepository::class)->find($request->getId());
        self::assertSame(SigningRequestStatus::Cancelled, $reloaded?->getStatus(), 'the first close stands');
    }

    public function testASignatureCannotLandOnARequestWithdrawnMeanwhile(): void
    {
        [$owner, $first] = $this->users();
        $document = $this->upload($owner);
        $request = $this->requests()->create($document, $owner, [$first], $this->inDays(7));

        $this->sql("UPDATE signing_request SET status = 'cancelled', closed_at = NOW() WHERE id = :id", $request);

        try {
            $this->signer()->sign($document, $this->makeCertificate($first), $first, self::PIN);
            self::fail('the signature must be refused');
        } catch (DomainException $e) {
            // Re-read under lock, the pending request is gone, so the signer is a
            // stranger to the document rather than a turn-holder.
            self::assertMatchesRegularExpression('/not your turn|only sign your own/', $e->getMessage());
        }
        static::getContainer()->get('doctrine')->resetManager();
        $reloaded = static::getContainer()->get(SigningRequestRepository::class)->find($request->getId());
        self::assertNotNull($reloaded);
        self::assertSame(SigningRequestStatus::Cancelled, $reloaded->getStatus(), 'not flipped back to Completed');
        self::assertCount(1, $reloaded->getDocument()->getVersions(), 'no version was minted');
    }

    /**
     * A revocation that lands while the token is signing: the PIN gate passed
     * on the row as it was, the token produced a signature, and the row is
     * revoked by the time the signature would go on the record. It must not.
     */
    public function testASignatureCannotLandWithACertificateRevokedWhileTheTokenWasSigning(): void
    {
        [$owner] = $this->users();
        $document = $this->upload($owner);
        $certificate = $this->makeCertificate($owner);

        $signer = $this->signerThatMeanwhile(function () use ($certificate): void {
            $this->sql("UPDATE certificate SET status = 'revoked', revoked_at = NOW(), revocation_reason = 'race' WHERE id = :id", $certificate);
        });

        $this->expectException(CertificateLockedException::class);
        $signer->sign($document, $certificate, $owner, self::PIN);
    }

    public function testASignatureCannotLandWithACertificatePutOnHoldWhileTheTokenWasSigning(): void
    {
        [$owner] = $this->users();
        $document = $this->upload($owner);
        $certificate = $this->makeCertificate($owner);

        $signer = $this->signerThatMeanwhile(function () use ($certificate): void {
            $this->sql("UPDATE certificate SET held_until = NOW() + INTERVAL '1 day' WHERE id = :id", $certificate);
        });

        $this->expectException(CertificateLockedException::class);
        $signer->sign($document, $certificate, $owner, self::PIN);
    }

    /**
     * Delivery lands while the token is signing. The ciphertext of the would-be
     * version was already stored when the check fails, so the rollback has to
     * take it back too - nothing left behind, on either store.
     */
    public function testASignatureCannotLandOnADocumentDeliveredWhileTheTokenWasSigning(): void
    {
        [$owner] = $this->users();
        $document = $this->upload($owner);
        $certificate = $this->makeCertificate($owner);
        $before = $this->storedKeys();

        $signer = $this->signerThatMeanwhile(function () use ($document): void {
            $this->sql('UPDATE document SET delivered_at = NOW() WHERE id = :id', $document);
        });

        try {
            $signer->sign($document, $certificate, $owner, self::PIN);
            self::fail('a delivered document is final');
        } catch (DomainException $e) {
            self::assertStringContainsString('delivered', $e->getMessage());
        }

        static::getContainer()->get('doctrine')->resetManager();
        $reloaded = static::getContainer()->get(DocumentRepository::class)->find($document->getId());
        self::assertNotNull($reloaded);
        self::assertCount(1, $reloaded->getVersions(), 'no signed version on the record');
        self::assertSame($before, $this->storedKeys(), 'no orphaned ciphertext in the object store');
    }

    /**
     * Two self-signatures at once: the second token session finishes after the
     * first signature committed. Sign-once must hold on the committed row, not
     * on what the second request loaded.
     */
    public function testASecondSelfSignatureCannotLandOnceTheFirstHasCommitted(): void
    {
        [$owner] = $this->users();
        $document = $this->upload($owner);
        $certificate = $this->makeCertificate($owner);

        $first = $this->signer();
        $second = $this->signerThatMeanwhile(function () use ($first, $document, $certificate, $owner): void {
            // The other request's whole signing completes while this token is busy.
            $first->sign($document, $certificate, $owner, self::PIN);
        });

        try {
            $second->sign($document, $certificate, $owner, self::PIN);
            self::fail('sign-once');
        } catch (DomainException $e) {
            self::assertStringContainsString('already been signed', $e->getMessage());
        }
        self::assertCount(2, $document->getVersions(), 'exactly one signature landed');
    }

    public function testASecondSendCannotOvertakeTheFirst(): void
    {
        [$owner, $first] = $this->users();
        $document = $this->upload($owner);

        $this->sql('UPDATE document SET awaiting_signatures_since = NOW() WHERE id = :id', $document);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already has a signature request out');
        $this->requests()->create($document, $owner, [$first], $this->inDays(7));
    }

    /** A signer whose "meanwhile" runs after the PIN gate and inside the token step - exactly when a concurrent commit can land. */
    private function signerThatMeanwhile(\Closure $meanwhile): DocumentSigner
    {
        return $this->signer(new class($meanwhile) implements PadesSignerInterface {
            public function __construct(private readonly \Closure $meanwhile)
            {
            }

            public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
            {
                ($this->meanwhile)();

                return $request->pdfBytes."\n% signed";
            }
        });
    }

    /** @return list<string> */
    private function storedKeys(): array
    {
        $keys = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchFirstColumn('SELECT storage_key FROM document_version ORDER BY storage_key');
        $storage = static::getContainer()->get(DocumentStorageInterface::class);

        return array_values(array_filter($keys, static fn (string $k): bool => $storage->exists($k)));
    }

    private function sql(string $statement, Document|SigningRequest|Certificate $entity): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement($statement, ['id' => $entity->getId()->toRfc4122()]);
    }

    /** @return array{User, User} */
    private function users(): array
    {
        $owner = $this->createUser($this->uniqueEmail('race-owner'));
        $first = $this->createUser($this->uniqueEmail('race-first'));
        $this->makeCertificate($first);

        return [$owner, $first];
    }

    private function upload(User $owner): Document
    {
        return static::getContainer()->get(DocumentUploader::class)->upload($owner, self::MINIMAL_PDF, 'Contract.pdf');
    }

    private function inDays(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('+%d days', $days));
    }

    private function requests(): SigningRequestService
    {
        return static::getContainer()->get(SigningRequestService::class);
    }

    private function signer(?PadesSignerInterface $pades = null): DocumentSigner
    {
        $c = static::getContainer();
        $caDir = sys_get_temp_dir().'/sigil-race-test-ca';
        @mkdir($caDir);
        file_put_contents($caDir.'/ca.crt', "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");

        return new DocumentSigner(
            $c->get(PinGate::class),
            $c->get(DocumentDownloader::class),
            $pades ?? new class implements PadesSignerInterface {
                public function sign(PadesSignRequest $request, #[\SensitiveParameter] string $pin): string
                {
                    return $request->pdfBytes."\n% signed";
                }
            },
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
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $now = new \DateTimeImmutable();
        $certificate = new Certificate(
            user: $user,
            serialNumber: bin2hex(random_bytes(16)),
            subjectDn: 'CN=Race Signer',
            certificatePem: '-----BEGIN CERTIFICATE-----',
            notBefore: $now->modify('-1 day'),
            notAfter: $now->modify('+1 year'),
            algorithmId: 'ECDSA-P384-SHA384/v1',
            tokenLabel: 'race-'.bin2hex(random_bytes(8)),
            keyLabel: 'sign',
            pinHash: password_hash(self::PIN, \PASSWORD_ARGON2ID),
        );
        $em->persist($certificate);
        $em->flush();

        return $certificate;
    }
}
