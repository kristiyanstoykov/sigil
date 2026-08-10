<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Functional\AuthWebTestCase;

final class TwoFactorLoginTest extends AuthWebTestCase
{
    public function testLoginWithTotpEnabledRequiresCodeBeforeDashboard(): void
    {
        $email = $this->uniqueEmail('totp');
        $this->createUser($email, verified: true, totpEnabled: true);

        $this->submitLogin($email, self::PASSWORD);

        // Mid-2FA the user is not fully authenticated: the dashboard must bounce
        // to the 2FA form, not render.
        $this->assertTwoFactorInProgress();

        // Submit the real current TOTP code through the scheb form.
        $crawler = $this->client->request('GET', '/2fa');
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action$="2fa_check"]')->form([
            '_auth_code' => $this->totpCode(self::TOTP_SECRET),
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testWrongTotpCodeIsRejected(): void
    {
        $email = $this->uniqueEmail('totpwrong');
        $this->createUser($email, verified: true, totpEnabled: true);

        $this->submitLogin($email, self::PASSWORD);
        $this->assertTwoFactorInProgress();

        $crawler = $this->client->request('GET', '/2fa');
        $form = $crawler->filter('form[action$="2fa_check"]')->form([
            '_auth_code' => '000000',
        ]);
        $this->client->submit($form);

        // Failure returns to the 2FA form, never the dashboard.
        $this->client->followRedirect();
        self::assertStringContainsString('/2fa', $this->client->getRequest()->getUri());

        $this->client->request('GET', '/');
        self::assertResponseRedirects();
        self::assertStringContainsString('/2fa', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testLogoutWorksMidTwoFactor(): void
    {
        $email = $this->uniqueEmail('totplogout');
        $this->createUser($email, verified: true, totpEnabled: true);

        $this->submitLogin($email, self::PASSWORD);
        $this->assertTwoFactorInProgress();

        $crawler = $this->client->request('GET', '/2fa');
        $form = $crawler->selectButton('Sign in with a different account')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/login');

        // Session is really gone: the dashboard now redirects to login, not /2fa.
        $this->client->request('GET', '/');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testSetupWithWrongCodeShowsErrorMessage(): void
    {
        $email = $this->uniqueEmail('setupwrong');
        $this->createUser($email, verified: true, totpEnabled: false);

        $this->submitLogin($email, self::PASSWORD);
        $this->client->followRedirect();

        $crawler = $this->client->request('GET', '/2fa/setup');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form(['two_factor_setup_form[code]' => '000000']);
        $crawler = $this->client->submit($form);

        // The error renders under the field, and 422 keeps Turbo from dropping
        // the response (a plain 200 re-render would be discarded).
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Invalid code', $crawler->filter('body')->text());
    }

    public function testLoginWithoutTotpGoesStraightToEnrollmentGate(): void
    {
        $email = $this->uniqueEmail('nototp');
        $this->createUser($email, verified: true, totpEnabled: false);

        $this->submitLogin($email, self::PASSWORD);

        // Fully authenticated (no TOTP configured) — the mandatory-2FA
        // enrollment subscriber must steer the user to /2fa/setup.
        $this->client->followRedirect();
        $location = $this->client->getResponse()->isRedirection()
            ? (string) $this->client->getResponse()->headers->get('Location')
            : $this->client->getRequest()->getUri();
        self::assertStringContainsString('/2fa/setup', $location);
    }
}
