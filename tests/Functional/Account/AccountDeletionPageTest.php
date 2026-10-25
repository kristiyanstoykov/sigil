<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Service\RecoveryCode;
use App\Certificate\Service\PinHasher;
use App\Core\Entity\User;
use App\Core\Repository\UserRepository;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The consequences page and DeleteAccountForm (ADR-015 §3): every factor is
 * re-proven, the recovery code is shown once, and the session ends.
 */
final class AccountDeletionPageTest extends AuthWebTestCase
{
    use AccountFixtures;

    public function testTheMenuLeadsToTheAccountPage(): void
    {
        $this->createUser($email = $this->uniqueEmail('member'), totpEnabled: true);
        $this->loginFully($email);

        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('header a[href="/account"]'));
        self::assertCount(1, $crawler->filter('a[href="/account/delete"]'));
    }

    public function testThePageSaysWhatWillHappen(): void
    {
        $user = $this->createUser($email = $this->uniqueEmail('leaver'), totpEnabled: true);
        $this->upload($user);
        $this->loginFully($email);

        $crawler = $this->client->request('GET', '/account/delete');

        self::assertResponseIsSuccessful();
        $preview = $crawler->filter('[data-deletion-preview]')->text();
        self::assertStringContainsString('1 unshared draft is erased', $preview);
        self::assertStringContainsString('no open signature requests', $preview);
        self::assertCount(1, $crawler->filter('a[href="/account/export"]'));
    }

    public function testDeletingShowsTheCodeOnceAndEndsTheSession(): void
    {
        $user = $this->createUser($email = $this->uniqueEmail('leaver'), totpEnabled: true);
        $id = $user->getId();
        $this->loginFully($email);

        $this->submitDeletion($email, self::PASSWORD, strtoupper($email));

        self::assertResponseRedirects('/account/deleted');
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertNotNull($mail);
        self::assertEmailAttachmentCount($mail, 1, 'the sealed closure record');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $code = $crawler->filter('[data-recovery-code]')->text();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{5}(-[A-Z2-7]{5}){3}$/', $code);

        $reloaded = $this->reload($id->toRfc4122());
        self::assertTrue($reloaded->isDeleted());
        self::assertTrue($this->get(PinHasher::class)->verify(RecoveryCode::normalize($code), (string) $reloaded->getRecoveryCodeHash()));

        // Once only, and nobody is signed in any more.
        $this->client->request('GET', '/account/deleted');
        self::assertResponseRedirects('/login');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');

        $this->submitLogin($email, self::PASSWORD);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Invalid credentials');
    }

    public function testATypoInTheAddressDeletesNothing(): void
    {
        $user = $this->createUser($email = $this->uniqueEmail('leaver'), totpEnabled: true);
        $this->loginFully($email);

        $this->submitDeletion($email, self::PASSWORD, 'someone-else@example.com');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="delete_account_form"]', 'That is not the address of this account.');
        self::assertFalse($this->reload($user->getId()->toRfc4122())->isDeleted());
    }

    public function testAWrongPasswordDeletesNothing(): void
    {
        $user = $this->createUser($email = $this->uniqueEmail('leaver'), totpEnabled: true);
        $this->loginFully($email);

        $this->submitDeletion($email, 'not-the-password', $email);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="delete_account_form"]', 'Password or authenticator code incorrect.');
        self::assertFalse($this->reload($user->getId()->toRfc4122())->isDeleted());
    }

    private function submitDeletion(string $email, string $password, string $typedEmail): Crawler
    {
        $crawler = $this->client->request('GET', '/account/delete');
        // The login just spent the current code; the replay guard has its own test.
        $this->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('UPDATE users SET last_totp_code = NULL, last_totp_used_at = NULL WHERE email = :email', ['email' => $email]);

        return $this->client->submit($crawler->selectButton('Delete my account')->form([
            'delete_account_form[password]' => $password,
            'delete_account_form[totpCode]' => $this->totpCode(self::TOTP_SECRET),
            'delete_account_form[confirmEmail]' => $typedEmail,
        ]));
    }

    private function reload(string $id): User
    {
        $this->get(EntityManagerInterface::class)->clear();
        $user = $this->get(UserRepository::class)->find($id);
        self::assertNotNull($user);

        return $user;
    }
}
