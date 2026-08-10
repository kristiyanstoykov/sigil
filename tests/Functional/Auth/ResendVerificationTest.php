<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Tests\Functional\AuthWebTestCase;

final class ResendVerificationTest extends AuthWebTestCase
{
    public function testUnverifiedLoginRedirectsToResendPageWithPrefilledEmail(): void
    {
        $email = $this->uniqueEmail('unverified');
        $this->createUser($email, verified: false);

        $this->submitLogin($email, self::PASSWORD);

        self::assertResponseRedirects('/verify/resend');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame($email, $crawler->filter('input[name="resend_verification_form[email]"]')->attr('value'), 'Attempted email must be prefilled');
    }

    public function testResendSendsEmailOnlyForExistingUnverifiedAccountButResponseIsIdentical(): void
    {
        $email = $this->uniqueEmail('resendme');
        $this->createUser($email, verified: false);

        // Existing unverified account → email sent
        $this->postResend($email);
        self::assertResponseRedirects('/verify/resend');
        self::assertEmailCount(1);
        $crawler = $this->client->followRedirect();
        $flashExisting = $crawler->filter('body')->text();

        // Unknown account → no email, same user-visible outcome (no enumeration)
        $this->postResend($this->uniqueEmail('ghost'));
        self::assertResponseRedirects('/verify/resend');
        self::assertEmailCount(0);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString(
            'If an unverified account exists',
            $flashExisting,
        );
        self::assertStringContainsString(
            'If an unverified account exists',
            $crawler->filter('body')->text(),
        );
    }

    public function testResendDoesNothingForAlreadyVerifiedAccount(): void
    {
        $email = $this->uniqueEmail('verified');
        $this->createUser($email, verified: true);

        $this->postResend($email);
        self::assertResponseRedirects('/verify/resend');
        self::assertEmailCount(0);
    }

    public function testResendIsRateLimitedPerEmail(): void
    {
        $email = $this->uniqueEmail('ratelimit');
        $this->createUser($email, verified: false);

        for ($i = 1; $i <= 3; ++$i) {
            $this->postResend($email);
            self::assertResponseRedirects('/verify/resend');
            self::assertEmailCount(1, message: sprintf('Attempt %d should still send', $i));
        }

        $this->postResend($email);
        self::assertResponseRedirects('/verify/resend');
        self::assertEmailCount(0, message: 'Fourth attempt within the window must be rate limited');

        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Too many requests', $crawler->filter('body')->text());
    }

    private function postResend(string $email): void
    {
        $crawler = $this->client->request('GET', '/verify/resend');
        $form = $crawler->filter('form')->form(['resend_verification_form[email]' => $email]);
        $this->client->submit($form);
    }
}
