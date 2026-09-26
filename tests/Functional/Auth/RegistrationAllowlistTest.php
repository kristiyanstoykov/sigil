<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Entity\AllowlistedEmail;
use App\Auth\Form\AllowlistAddForm;
use App\Auth\Repository\AllowlistedEmailRepository;
use App\Auth\Service\RegistrationAllowlist;
use App\Core\Entity\User;
use App\Core\Exception\DomainException;
use App\Core\Repository\UserRepository;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class RegistrationAllowlistTest extends AuthWebTestCase
{
    public function testAnUninvitedAddressGetsTheSameAnswerAndNoAccount(): void
    {
        $invited = $this->uniqueEmail('invited');
        $this->allowlist($invited);
        $this->register($invited);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        $invitedFlash = $this->client->getCrawler()->filter('[data-flash-stack]')->text();

        $stranger = $this->uniqueEmail('stranger');
        $this->register($stranger);
        self::assertResponseRedirects('/login');
        self::assertEmailCount(0);
        $this->client->followRedirect();

        self::assertSame($invitedFlash, $this->client->getCrawler()->filter('[data-flash-stack]')->text(), 'The page must not reveal whether an address is invited');
        self::assertNull($this->users()->findOneByEmail($stranger), 'An uninvited address must never get an account');
        self::assertNotNull($this->users()->findOneByEmail($invited));
    }

    public function testTheAllowlistIgnoresCase(): void
    {
        $email = $this->uniqueEmail('mixed');
        $this->allowlist(strtoupper($email));

        $this->register($email);

        self::assertNotNull($this->users()->findOneByEmail($email));
    }

    public function testASignerCannotReachTheAdminPage(): void
    {
        $email = $this->uniqueEmail('signer');
        $this->createUser($email, totpEnabled: true);
        $this->loginFully($email);

        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('a[href="/admin/allowlist"]'), 'The nav item is for admins only');

        $this->client->request('GET', '/admin/allowlist');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAdminInvitesAndWithdrawsAnAddress(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', '/admin/allowlist');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/admin/allowlist"]'), 'Admins get the nav item');

        $guest = $this->uniqueEmail('guest');
        $this->client->submit($crawler->filter('form[name="allowlist_add_form"]')->form([
            'allowlist_add_form['.AllowlistAddForm::E_EMAIL.']' => $guest,
        ]));
        self::assertResponseRedirects('/admin/allowlist');
        self::assertTrue($this->allowlistService()->permits($guest));

        $crawler = $this->client->request('GET', '/admin/allowlist');
        $this->client->submit($crawler->filter('form[name="allowlist_add_form"]')->form([
            'allowlist_add_form['.AllowlistAddForm::E_EMAIL.']' => strtoupper($guest),
        ]));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="allowlist_add_form"]', 'already on the allowlist');

        $entry = $this->entries()->findOneByEmail($guest);
        self::assertInstanceOf(AllowlistedEmail::class, $entry);
        $crawler = $this->client->request('GET', '/admin/allowlist');
        $this->client->submit($crawler->filter(sprintf('form[action$="/admin/allowlist/%s/remove"]', $entry->getId()->toRfc4122()))->form());
        self::assertResponseRedirects('/admin/allowlist');
        self::assertFalse($this->allowlistService()->permits($guest));
    }

    public function testARegisteredAddressHasNoWithdrawButtonAndCannotBeRemoved(): void
    {
        $admin = $this->loginAsAdmin();
        $entry = $this->entries()->findOneByEmail($admin->getEmail());
        self::assertInstanceOf(AllowlistedEmail::class, $entry);

        $crawler = $this->client->request('GET', '/admin/allowlist');
        self::assertCount(0, $crawler->filter(sprintf('form[action$="/admin/allowlist/%s/remove"]', $entry->getId()->toRfc4122())));

        $this->expectException(DomainException::class);
        $this->allowlistService()->remove($entry, $admin);
    }

    public function testTheGrantCommandPromotesAnExistingAccountOnly(): void
    {
        $email = $this->uniqueEmail('promote');
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('sigil:admin:grant'));

        self::assertSame(1, $tester->execute(['email' => $email]), 'No account, no grant');

        $this->createUser($email);
        self::assertSame(0, $tester->execute(['email' => $email]));

        $this->em()->clear();
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertTrue($this->allowlistService()->permits($email), 'An admin is on the allowlist');
    }

    public function testTheCreateCommandSeedsALoginReadyAdmin(): void
    {
        $email = $this->uniqueEmail('seed');
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('sigil:admin:create'));
        $tester->setInputs(['Kristiyan', 'Stoykov', 'short', self::PASSWORD, 'mismatch-'.self::PASSWORD, self::PASSWORD, self::PASSWORD]);

        self::assertSame(0, $tester->execute(['email' => strtoupper($email)]), $tester->getDisplay());
        self::assertStringContainsString('too short', $tester->getDisplay());
        self::assertStringContainsString('do not match', $tester->getDisplay());

        $this->em()->clear();
        $user = $this->users()->findOneByEmail($email);
        self::assertInstanceOf(User::class, $user);
        self::assertSame($email, $user->getEmail(), 'Stored lower-cased');
        self::assertTrue($user->isVerified());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertTrue($this->allowlistService()->permits($email));

        self::assertSame(1, $tester->execute(['email' => $email]), 'A second run must not overwrite the account');

        // The seeded password logs in; TOTP enrolment is what comes next.
        $this->submitLogin($email, self::PASSWORD);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseRedirects('/2fa/setup');
    }

    private function loginAsAdmin(): User
    {
        $email = $this->uniqueEmail('admin');
        $admin = $this->createUser($email, totpEnabled: true);
        $admin->setRoles(['ROLE_SIGNER', 'ROLE_ADMIN']);
        $this->em()->flush();
        $this->allowlist($email);
        $this->loginFully($email);

        return $admin;
    }

    private function register(string $email): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('form')->form([
            'registration_form[firstName]' => 'Ana',
            'registration_form[lastName]' => 'Petrova',
            'registration_form[email]' => $email,
            'registration_form[password][first]' => self::PASSWORD,
            'registration_form[password][second]' => self::PASSWORD,
        ]));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function users(): UserRepository
    {
        return static::getContainer()->get(UserRepository::class);
    }

    private function entries(): AllowlistedEmailRepository
    {
        return static::getContainer()->get(AllowlistedEmailRepository::class);
    }

    private function allowlistService(): RegistrationAllowlist
    {
        return static::getContainer()->get(RegistrationAllowlist::class);
    }
}
