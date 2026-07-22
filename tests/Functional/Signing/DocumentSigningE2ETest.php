<?php

declare(strict_types=1);

namespace App\Tests\Functional\Signing;

use App\Certificate\Service\CertificateIssuer;
use App\Certificate\Service\Pkcs11TokenManager;
use App\Document\Enum\DocumentVersionKind;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\DocumentUploader;
use App\Signing\Service\DocumentSigner;
use App\Tests\Functional\AuthWebTestCase;
use Symfony\Component\Process\Process;

/**
 * The full signing chain end to end: upload → issue a real CA-signed cert in a
 * SoftHSM token → sign the latest version with the real pyHanko/PKCS#11 driver
 * → assert a Signed DocumentVersion whose bytes are a valid PAdES that chains to
 * the Sigil CA. Requires an initialized CA (sigil:ca:init). Test env uses the
 * "none" TSA, so the signature is PAdES-B-B (no network).
 */
class DocumentSigningE2ETest extends AuthWebTestCase
{
    private const PIN = '123456';

    /** @var list<string> */
    private array $tokensToCleanUp = [];

    protected function tearDown(): void
    {
        $manager = static::getContainer()->get(Pkcs11TokenManager::class);
        foreach ($this->tokensToCleanUp as $label) {
            try {
                $manager->deleteToken($label);
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    public function testUploadIssueSignProducesAValidPadesSignedVersion(): void
    {
        $container = static::getContainer();
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $pdf = (string) file_get_contents($projectDir.'/tests/Fixtures/blank.pdf');

        $user = $this->createUser($this->uniqueEmail('e2e-sign'));
        $document = $container->get(DocumentUploader::class)->upload($user, $pdf, 'Agreement.pdf');

        $certificate = $container->get(CertificateIssuer::class)->issueForUser($user, self::PIN);
        $this->tokensToCleanUp[] = $certificate->getTokenLabel();

        $signedVersion = $container->get(DocumentSigner::class)->sign($document, $certificate, $user, self::PIN);

        // A second, Signed version exists; the original is preserved.
        self::assertSame(DocumentVersionKind::Signed, $signedVersion->getKind());
        self::assertSame(2, $signedVersion->getVersionNumber());
        self::assertCount(2, $document->getVersions());

        $signedBytes = $container->get(DocumentDownloader::class)->download($signedVersion, $user);
        self::assertStringStartsWith('%PDF', $signedBytes);
        // The signed copy carries a signature the original did not.
        self::assertGreaterThan(\strlen($pdf), \strlen($signedBytes));

        // It is a real PAdES signature that chains to the Sigil CA.
        self::assertSame('INTACT TRUSTED', $this->validatePades($signedBytes, $projectDir));
    }

    /**
     * Regression: signing an already-signed PDF must not collide with a field
     * the document already carries. The fixture holds a filled "Signature2"
     * field (the name our old version-numbered scheme produced), which used to
     * raise "Signature field ... appears to be filled already".
     */
    public function testSigningAnAlreadySignedPdfDoesNotCollideOnFieldName(): void
    {
        $container = static::getContainer();
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $pdf = (string) file_get_contents($projectDir.'/tests/Fixtures/signed-sig2.pdf');

        $user = $this->createUser($this->uniqueEmail('e2e-cosign'));
        $document = $container->get(DocumentUploader::class)->upload($user, $pdf, 'Countersign.pdf');

        $certificate = $container->get(CertificateIssuer::class)->issueForUser($user, self::PIN);
        $this->tokensToCleanUp[] = $certificate->getTokenLabel();

        $signedVersion = $container->get(DocumentSigner::class)->sign($document, $certificate, $user, self::PIN);

        self::assertSame(DocumentVersionKind::Signed, $signedVersion->getKind());
        $signedBytes = $container->get(DocumentDownloader::class)->download($signedVersion, $user);
        self::assertSame('INTACT TRUSTED', $this->validatePades($signedBytes, $projectDir));
    }

    /**
     * Validates the last embedded signature with pyHanko against var/ca/ca.crt.
     */
    private function validatePades(string $pdfBytes, string $projectDir): string
    {
        $pdfFile = (string) tempnam(sys_get_temp_dir(), 'sigil-signed-');
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
}
