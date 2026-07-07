<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificate;

use App\Certificate\Service\Pkcs11TokenManager;
use App\Core\Entity\User;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * End-to-end web flow: wizard issues a real cert (SoftHSM + CA), list/detail
 * render, download serves the PEM, and the enrollment gate behaves.
 */
final class CertificateWebTest extends AuthWebTestCase
{
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

    private function loginFully(string $email): void
    {
        $this->submitLogin($email, self::PASSWORD);
        $crawler = $this->client->request('GET', '/2fa');
        $form = $crawler->filter('form[action$="2fa_check"]')->form([
            '_auth_code' => $this->totpCode(self::TOTP_SECRET),
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();
    }

    public function testWizardIssuesCertificateAndPagesRender(): void
    {
        $email = $this->uniqueEmail('certweb');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->loginFully($email);

        // wizard
        $crawler = $this->client->request('GET', '/certificates/new');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Protect it with a PIN', (string) $this->client->getResponse()->getContent());

        $form = $crawler->selectButton('Generate my certificate')->form([
            'new_certificate_form[pin][first]' => '123456',
            'new_certificate_form[pin][second]' => '123456',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/certificates/[0-9a-f-]+$#', $location);

        $this->rememberTokenForCleanup($email);

        // detail page
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Active', $html);
        self::assertStringContainsString('ECDSA P-384 with SHA-384', $html);

        // list page
        $this->client->request('GET', '/certificates');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1 of 3', (string) $this->client->getResponse()->getContent());

        // download
        $this->client->request('GET', $location.'/download');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('BEGIN CERTIFICATE', (string) $this->client->getResponse()->getContent());

        // dashboard slider shows the real certificate
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ECDSA P-384 with SHA-384', (string) $this->client->getResponse()->getContent());
    }

    public function testCertificateOfAnotherUserIsNotFound(): void
    {
        $emailA = $this->uniqueEmail('owner');
        $this->createUser($emailA, verified: true, totpEnabled: true);
        $this->loginFully($emailA);

        $crawler = $this->client->request('GET', '/certificates/new');
        $form = $crawler->selectButton('Generate my certificate')->form([
            'new_certificate_form[pin][first]' => '123456',
            'new_certificate_form[pin][second]' => '123456',
        ]);
        $this->client->submit($form);
        $certUrl = (string) $this->client->getResponse()->headers->get('Location');
        $this->rememberTokenForCleanup($emailA);

        // second user must get 404, not 403 — existence is not revealed
        $this->client->restart(); // drop user A's session
        $emailB = $this->uniqueEmail('intruder');
        $this->createUser($emailB, verified: true, totpEnabled: true);
        $this->loginFully($emailB);

        $this->client->request('GET', $certUrl);
        self::assertResponseStatusCodeSame(404);
    }

    private function rememberTokenForCleanup(string $email): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User);
        foreach ($em->getRepository(\App\Certificate\Entity\Certificate::class)->findBy(['user' => $user]) as $certificate) {
            $this->tokensToCleanUp[] = $certificate->getTokenLabel();
        }
    }
}
