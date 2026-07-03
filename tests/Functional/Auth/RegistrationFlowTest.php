<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Core\Entity\User;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

final class RegistrationFlowTest extends AuthWebTestCase
{
    public function testRegistrationSendsVerificationEmailAndLinkVerifiesAccount(): void
    {
        $email = $this->uniqueEmail('register');

        $crawler = $this->client->request('GET', '/register');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            'registration_form[firstName]' => 'Ana',
            'registration_form[lastName]' => 'Petrova',
            'registration_form[email]' => $email,
            'registration_form[password][first]' => self::PASSWORD,
            'registration_form[password][second]' => self::PASSWORD,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, 'Registration must persist the user');
        self::assertFalse($user->isVerified(), 'A fresh registration must start unverified');

        $signedUrl = $this->extractSignedUrl();

        $this->client->request('GET', $signedUrl);
        self::assertResponseRedirects('/login');

        // The kernel reboots between requests, so re-fetch instead of refresh.
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::assertTrue($user->isVerified(), 'Following the signed link must verify the account');
    }

    public function testTamperedVerificationLinkIsRejected(): void
    {
        $email = $this->uniqueEmail('tamper');
        $this->createUser($email, verified: false);

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form([
            'registration_form[firstName]' => 'Bad',
            'registration_form[lastName]' => 'Actor',
            'registration_form[email]' => $this->uniqueEmail('victim'),
            'registration_form[password][first]' => self::PASSWORD,
            'registration_form[password][second]' => self::PASSWORD,
        ]);
        $this->client->submit($form);

        $signedUrl = $this->extractSignedUrl();
        $tampered = preg_replace('/signature=[^&]+/', 'signature=forged', $signedUrl);

        $this->client->request('GET', $tampered);
        self::assertResponseRedirects('/register');
    }

    private function extractSignedUrl(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $message);

        $body = (string) $message->getHtmlBody();
        if ($body === '') {
            // The templated email may not be rendered by the null transport;
            // fall back to the signed URL placed in the template context.
            $context = $message->getContext();
            self::assertArrayHasKey('signedUrl', $context);

            return $context['signedUrl'];
        }

        self::assertMatchesRegularExpression('#(https?://[^"\s]*/verify/email[^"\s]*)#', $body, 'Email must contain the signed verification link');
        preg_match('#(https?://[^"\s]*/verify/email[^"\s]*)#', $body, $m);

        return html_entity_decode($m[1]);
    }
}
