<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Renders every page of the app in its real auth state and saves the HTML to
 * docs/design/html/. Doubles as a smoke test (every page must return 200).
 * The snapshots feed the design brief in docs/design/DESIGN_BRIEF.md.
 */
final class PageSnapshotTest extends AuthWebTestCase
{
    private const OUT_DIR = '/docs/design/html';

    public function testPublicPagesRenderAndAreSnapshotted(): void
    {
        foreach ([
            'login' => '/login',
            'register' => '/register',
            'reset_password_request' => '/reset-password',
            'resend_verification' => '/verify/resend',
        ] as $name => $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful(sprintf('%s must render', $path));
            $this->snapshot($name);
        }
    }

    public function testTwoFactorLoginPageRendersAndIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshot2fa');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->submitLogin($email, self::PASSWORD);

        $this->client->request('GET', '/2fa');
        self::assertResponseIsSuccessful();
        $this->snapshot('2fa_login');
    }

    public function testTwoFactorSetupPageRendersAndIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshotsetup');
        $this->createUser($email, verified: true, totpEnabled: false);
        $this->submitLogin($email, self::PASSWORD);

        $this->client->request('GET', '/2fa/setup');
        self::assertResponseIsSuccessful();
        $this->snapshot('2fa_setup');
    }

    public function testDashboardRendersAndIsSnapshotted(): void
    {
        $email = $this->uniqueEmail('snapshotdash');
        $this->createUser($email, verified: true, totpEnabled: true);
        $this->submitLogin($email, self::PASSWORD);

        $crawler = $this->client->request('GET', '/2fa');
        $form = $crawler->filter('form[action$="2fa_check"]')->form([
            '_auth_code' => $this->totpCode(self::TOTP_SECRET),
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertResponseIsSuccessful();
        $this->snapshot('dashboard');
    }

    private function snapshot(string $name): void
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . self::OUT_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($dir . '/' . $name . '.html', $this->client->getResponse()->getContent());
    }
}
