<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificate;

use App\Tests\Functional\AuthWebTestCase;

/**
 * Regression: RepeatedType maps violations to its FIRST child, so templates
 * must render the children's errors, not only the parent's.
 */
final class NewCertificateValidationTest extends AuthWebTestCase
{
    public function testEmptyPinSubmitShowsValidationError(): void
    {
        $email = $this->uniqueEmail('pinval');
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
            'new_certificate_form[pin][first]' => '',
            'new_certificate_form[pin][second]' => '',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Please choose a PIN.', (string) $this->client->getResponse()->getContent());
    }

    public function testNonNumericPinShowsFormatError(): void
    {
        $email = $this->uniqueEmail('pinfmt');
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
            'new_certificate_form[pin][first]' => 'abc123',
            'new_certificate_form[pin][second]' => 'abc123',
        ]);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('The PIN must be 6 to 8 digits.', (string) $this->client->getResponse()->getContent());
    }
}
