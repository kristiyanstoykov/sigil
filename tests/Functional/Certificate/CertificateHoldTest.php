<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificate;

use App\Certificate\Entity\Certificate;
use App\Certificate\Enum\CertificateDisplayStatus;
use App\Certificate\Enum\CertificateStatus;
use App\Certificate\Service\Pkcs11TokenManager;
use App\Core\Entity\User;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The 72h certificate hold (virtual "certificateHold" state) and the PIN gate
 * on the lifecycle actions: hold, resume and revoke all require the PIN while
 * the certificate is otherwise usable.
 */
final class CertificateHoldTest extends AuthWebTestCase
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

    public function testHoldRequiresPinAndSuspendsFor72Hours(): void
    {
        $certUrl = $this->issueCertificate($this->uniqueEmail('hold'));

        // wrong PIN → no hold, ADR-008 counter ticks
        $this->submitModalForm($certUrl, 'hold', '999999');
        $this->client->followRedirect();
        self::assertStringContainsString('Incorrect PIN', $this->pageContent());
        self::assertFalse($this->reloadCertificate($certUrl)->isOnHold(new \DateTimeImmutable()));

        // correct PIN → held for ~72 hours, unusable, shown as "On hold"
        $this->submitModalForm($certUrl, 'hold', self::PIN);
        $this->client->followRedirect();

        $certificate = $this->reloadCertificate($certUrl);
        $now = new \DateTimeImmutable();
        self::assertTrue($certificate->isOnHold($now));
        self::assertFalse($certificate->isUsable($now));
        self::assertSame(CertificateStatus::Active, $certificate->getStatus(), 'DB status stays Active while held');
        $hours = ($certificate->getHeldUntil()->getTimestamp() - $now->getTimestamp()) / 3600;
        self::assertEqualsWithDelta(Certificate::HOLD_HOURS, $hours, 0.1);
        self::assertStringContainsString('On hold', $this->pageContent());
    }

    public function testResumeWithPinLiftsTheHoldEarly(): void
    {
        $certUrl = $this->issueCertificate($this->uniqueEmail('resume'));
        $this->submitModalForm($certUrl, 'hold', self::PIN);

        // wrong PIN keeps the hold
        $this->submitModalForm($certUrl, 'resume', '999999');
        self::assertTrue($this->reloadCertificate($certUrl)->isOnHold(new \DateTimeImmutable()));

        // correct PIN lifts it
        $this->submitModalForm($certUrl, 'resume', self::PIN);
        $this->client->followRedirect();

        $certificate = $this->reloadCertificate($certUrl);
        self::assertFalse($certificate->isOnHold(new \DateTimeImmutable()));
        self::assertTrue($certificate->isUsable(new \DateTimeImmutable()));
        self::assertStringContainsString('enabled again', $this->pageContent());
    }

    public function testHoldLiftsItselfOnceTheMomentPasses(): void
    {
        $certUrl = $this->issueCertificate($this->uniqueEmail('lapse'));
        $this->submitModalForm($certUrl, 'hold', self::PIN);

        // no scheduler: a heldUntil in the past simply stops mattering
        $certificate = $this->reloadCertificate($certUrl);
        $certificate->hold(new \DateTimeImmutable('-1 minute'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $now = new \DateTimeImmutable();
        self::assertFalse($certificate->isOnHold($now));
        self::assertTrue($certificate->isUsable($now));
        self::assertSame(CertificateDisplayStatus::Active, $certificate->getDisplayStatus($now));

        $this->client->request('GET', $certUrl);
        self::assertStringContainsString('Active', $this->pageContent());
        self::assertStringNotContainsString('On hold', $this->pageContent());
    }

    public function testRevokeRequiresPinWhileUsable(): void
    {
        $certUrl = $this->issueCertificate($this->uniqueEmail('revokepin'));

        // wrong PIN → still active
        $this->submitModalForm($certUrl, 'revoke', '999999');
        $this->client->followRedirect();
        self::assertStringContainsString('Incorrect PIN', $this->pageContent());
        self::assertSame(CertificateStatus::Active, $this->reloadCertificate($certUrl)->getStatus());

        // correct PIN → revoked
        $this->submitModalForm($certUrl, 'revoke', self::PIN);
        $this->client->followRedirect();
        self::assertSame(CertificateStatus::Revoked, $this->reloadCertificate($certUrl)->getStatus());
    }

    public function testHeldCertificateCanBeRevokedWithPin(): void
    {
        $certUrl = $this->issueCertificate($this->uniqueEmail('revokeheld'));
        $this->submitModalForm($certUrl, 'hold', self::PIN);

        $this->submitModalForm($certUrl, 'revoke', self::PIN);
        self::assertSame(CertificateStatus::Revoked, $this->reloadCertificate($certUrl)->getStatus());
    }

    // -- helpers -------------------------------------------------------------

    /** Full login + wizard issue; returns the certificate detail URL. */
    private function issueCertificate(string $email): string
    {
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->submitLogin($email, self::PASSWORD);
        $crawler = $this->client->request('GET', '/2fa');
        $form = $crawler->filter('form[action$="2fa_check"]')->form([
            '_auth_code' => $this->totpCode(self::TOTP_SECRET),
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/certificates/new');
        $form = $crawler->selectButton('Generate my certificate')->form([
            'new_certificate_form[pin][first]' => self::PIN,
            'new_certificate_form[pin][second]' => self::PIN,
        ]);
        $this->client->submit($form);
        $certUrl = (string) $this->client->getResponse()->headers->get('Location');

        $this->tokensToCleanUp[] = $this->certificateFromUrl($certUrl)->getTokenLabel();

        return $certUrl;
    }

    /** Loads the detail page and submits the hold/resume/revoke modal form with the given PIN. */
    private function submitModalForm(string $certUrl, string $action, string $pin): void
    {
        $crawler = $this->client->request('GET', $certUrl);
        $form = $crawler->filter(sprintf('form[action$="/%s"]', $action))->form(['_pin' => $pin]);
        $this->client->submit($form);
        self::assertResponseRedirects();
    }

    private function reloadCertificate(string $certUrl): Certificate
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $certificate = $this->certificateFromUrl($certUrl);
        $em->refresh($certificate);

        return $certificate;
    }

    private function pageContent(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    private function certificateFromUrl(string $certUrl): Certificate
    {
        $id = substr($certUrl, (int) strrpos($certUrl, '/') + 1);
        $certificate = static::getContainer()->get(EntityManagerInterface::class)
            ->getRepository(Certificate::class)->find($id);
        \assert($certificate instanceof Certificate);

        return $certificate;
    }
}
