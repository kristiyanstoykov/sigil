<?php

declare(strict_types=1);

namespace App\Tests\Functional\Signing;

use App\Certificate\Entity\Certificate;
use App\Certificate\Service\PinGate;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Document\Entity\Document;
use App\Document\Repository\DocumentKeyGrantRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentUploader;
use App\Document\Service\DocumentVersionWriter;
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
use App\Signing\Twig\SigningRequestExtension;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The ordered multi-signer queue: who may sign when, and who can decrypt what.
 * Real services throughout, with a fake {@see PadesSignerInterface} so no PKCS#11
 * token is needed.
 */
class SigningRequestTest extends AuthWebTestCase
{
    private const PIN = '135790';
    private const MINIMAL_PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n"
        ."3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R /Size 4 >>\nstartxref\n0\n%%EOF";

    public function testSignersAreGrantedAccessOneTurnAtATime(): void
    {
        [$owner, $first, $second] = $this->threeSigners();
        $document = $this->upload($owner);

        $request = $this->service()->create($document, $owner, [$first, $second], $this->inDays(7));

        $grants = static::getContainer()->get(DocumentKeyGrantRepository::class);
        $latest = $document->getLatestVersion();
        self::assertNotNull($latest);

        // The turn is the access: only the first signer can read anything yet.
        self::assertNotNull($grants->findForVersionAndUser($latest, $first));
        self::assertNull($grants->findForVersionAndUser($latest, $second));
        self::assertTrue($request->isTurnOf($first));
        self::assertFalse($request->isTurnOf($second));
    }

    public function testSecondSignerCannotJumpTheQueue(): void
    {
        [$owner, $first, $second] = $this->threeSigners();
        $document = $this->upload($owner);
        $this->service()->create($document, $owner, [$first, $second], $this->inDays(7));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('It is not your turn');

        $this->signer()->sign($document, $this->makeCertificate($second), $second, self::PIN);
    }

    public function testEachSignatureHandsTheTurnAndTheKeyOnUntilTheRequestCompletes(): void
    {
        [$owner, $first, $second] = $this->threeSigners();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first, $second], $this->inDays(7));

        $this->signer()->sign($document, $this->makeCertificate($first), $first, self::PIN);

        $grants = static::getContainer()->get(DocumentKeyGrantRepository::class);
        $signedVersion = $document->getLatestVersion();
        self::assertNotNull($signedVersion);

        // The second signer is handed the version they have to sign, not the
        // original, and only now.
        self::assertNotNull($grants->findForVersionAndUser($signedVersion, $second));
        self::assertTrue($request->isTurnOf($second));
        self::assertSame(SigningRequestStatus::Pending, $request->getStatus());

        $this->signer()->sign($document, $this->makeCertificate($second), $second, self::PIN);

        self::assertSame(SigningRequestStatus::Completed, $request->getStatus());
        self::assertSame(2, $request->signedCount());
        self::assertCount(3, $document->getVersions(), 'original + one version per signature');
    }

    public function testTheDocumentLandsAListOfWhoSignedAndExactlyWhen(): void
    {
        [$owner, $first, $second] = $this->threeSigners();
        $document = $this->upload($owner);
        $this->service()->create($document, $owner, [$first, $second], $this->inDays(7));

        $before = new \DateTimeImmutable('-1 minute');
        $this->signer()->sign($document, $this->makeCertificate($first), $first, self::PIN);
        $this->signer()->sign($document, $this->makeCertificate($second), $second, self::PIN);

        $signatures = static::getContainer()->get(SigningRequestExtension::class)->signatures($document);

        self::assertCount(2, $signatures);
        self::assertSame($first->getEmail(), $signatures[0]['user']->getEmail());
        self::assertSame($second->getEmail(), $signatures[1]['user']->getEmail());
        // Signing order is chronological order, and each row carries the moment.
        self::assertGreaterThan($before, $signatures[0]['signedAt']);
        self::assertGreaterThanOrEqual($signatures[0]['signedAt'], $signatures[1]['signedAt']);
        self::assertSame(2, $signatures[0]['versionNumber']);
        self::assertSame(3, $signatures[1]['versionNumber']);
        self::assertTrue($signatures[0]['viaRequest']);
    }

    public function testASelfSignedDocumentStillNamesItsSigner(): void
    {
        [$owner] = $this->threeSigners();
        $document = $this->upload($owner);

        $this->signer()->sign($document, $this->makeCertificate($owner), $owner, self::PIN);

        $signatures = static::getContainer()->get(SigningRequestExtension::class)->signatures($document);

        // No request ran, so the version's own timestamp stands in.
        self::assertCount(1, $signatures);
        self::assertSame($owner->getEmail(), $signatures[0]['user']->getEmail());
        self::assertFalse($signatures[0]['viaRequest']);
    }

    public function testSendIsRefusedWhenAnySignerCannotSign(): void
    {
        [$owner, $first] = $this->threeSigners();
        $withoutCertificate = $this->createUser($this->uniqueEmail('nocert'));
        $document = $this->upload($owner);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('no usable certificate');

        $this->service()->create($document, $owner, [$first, $withoutCertificate], $this->inDays(7));
    }

    public function testDeadlineBeyondTheCeilingIsRefused(): void
    {
        [$owner, $first] = $this->threeSigners();
        $document = $this->upload($owner);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('at most '.SigningRequest::MAX_DEADLINE_DAYS);

        $this->service()->create($document, $owner, [$first], $this->inDays(SigningRequest::MAX_DEADLINE_DAYS + 1));
    }

    public function testADocumentGetsOneRequestInItsLifeEvenAfterOneClosed(): void
    {
        [$owner, $first, $second] = $this->threeSigners();
        $document = $this->upload($owner);

        $request = $this->service()->create($document, $owner, [$first], $this->inDays(7));
        $this->service()->decline($request, $first, null);
        self::assertSame(SigningRequestStatus::Declined, $request->getStatus());

        // Closed is still spent: the sealed receipt names a fixed audience and
        // outcome, and a second queue would sign a different version.
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been through a signature request');

        $this->service()->create($document, $owner, [$second], $this->inDays(7));
    }

    public function testCancellingTakesTheCurrentSignersAccessAway(): void
    {
        [$owner, $first] = $this->threeSigners();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first], $this->inDays(7));

        $this->service()->cancel($request, $owner);

        $grants = static::getContainer()->get(DocumentKeyGrantRepository::class);
        self::assertSame(SigningRequestStatus::Cancelled, $request->getStatus());
        self::assertFalse($grants->hasGrantForDocument($document, $first));
    }

    public function testDecliningClosesTheQueueAndTakesTheDeclinersAccessAway(): void
    {
        [$owner, $first, $second] = $this->threeSigners();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first, $second], $this->inDays(7));

        $this->service()->decline($request, $first, 'Wrong counterparty in clause 4.');

        self::assertSame(SigningRequestStatus::Declined, $request->getStatus());
        self::assertTrue($request->signerFor($first)?->hasDeclined());
        self::assertSame('Wrong counterparty in clause 4.', $request->declinedBy()?->getDeclineReason());

        // The refusal stops the queue: the next signer is never handed the key.
        $grants = static::getContainer()->get(DocumentKeyGrantRepository::class);
        self::assertFalse($grants->hasGrantForDocument($document, $first), 'the decliner loses what the turn gave them');
        self::assertFalse($grants->hasGrantForDocument($document, $second), 'nobody after them was ever asked');
    }

    public function testAReasonIsOptionalWhenDeclining(): void
    {
        [$owner, $first] = $this->threeSigners();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first], $this->inDays(7));

        $this->service()->decline($request, $first, null);

        self::assertSame(SigningRequestStatus::Declined, $request->getStatus());
        self::assertNull($request->declinedBy()?->getDeclineReason());
    }

    public function testOnlyTheSignerWhoseTurnItIsCanDecline(): void
    {
        [$owner, $first, $second] = $this->threeSigners();
        $document = $this->upload($owner);
        $request = $this->service()->create($document, $owner, [$first, $second], $this->inDays(7));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('not your turn');

        $this->service()->decline($request, $second, 'I would rather not.');
    }

    public function testSweepDeletesAnUnsignedDocumentAndKeepsASignedOne(): void
    {
        $container = static::getContainer();

        // Nobody signed: the document goes with the request.
        [$owner, $first] = $this->threeSigners();
        $unsigned = $this->upload($owner);
        $unsignedId = $unsigned->getId()->toRfc4122();
        $this->overdueRequest($unsigned, $owner, [$first]);

        // One signature: the document stays, the request is marked Expired.
        [$owner2, $signerA, $signerB] = $this->threeSigners();
        $partly = $this->upload($owner2);
        $partlyId = $partly->getId()->toRfc4122();
        $request = $this->service()->create($partly, $owner2, [$signerA, $signerB], $this->inDays(7));
        $this->signer()->sign($partly, $this->makeCertificate($signerA), $signerA, self::PIN);
        $this->makeOverdue($request);

        $this->runCommand('sigil:signing:sweep');

        $documents = $container->get(DocumentRepository::class);
        $requests = $container->get(SigningRequestRepository::class);

        self::assertNull($documents->find($unsignedId), 'an unsigned expired document is destroyed');

        $kept = $documents->find($partlyId);
        self::assertNotNull($kept, 'a partly signed document is kept as evidence');
        self::assertSame(SigningRequestStatus::Expired, $requests->findLatestForDocument($kept)?->getStatus());
    }

    /** @return array{User, User, User} */
    private function threeSigners(): array
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

    /**
     * A request whose deadline is already in the past, written straight to the row.
     *
     * @param list<User> $signers
     */
    private function overdueRequest(Document $document, User $owner, array $signers): SigningRequest
    {
        $request = $this->service()->create($document, $owner, $signers, $this->inDays(1));
        $this->makeOverdue($request);

        return $request;
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

    private function service(): SigningRequestService
    {
        return static::getContainer()->get(SigningRequestService::class);
    }

    private function signer(): DocumentSigner
    {
        $c = static::getContainer();
        $caPath = sys_get_temp_dir().'/sigil-request-test-ca.crt';
        file_put_contents($caPath, "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n");

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
            $c->get(SigningRequestNotifier::class),
            $caPath,
        );
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
}
