<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\Repository\AccountClosureRecordRepository;
use App\Account\Repository\ArchivedUserKeyRepository;
use App\Account\Service\AccountDeleter;
use App\Account\Service\ClosureRecordSealer;
use App\AuditLog\Repository\AuditLogEntryRepository;
use App\Core\Entity\User;
use App\Core\Repository\UserRepository;
use App\Document\Repository\DocumentRepository;
use App\Document\Service\DocumentDownloader;
use App\Document\Service\KeyManagementService;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Coming back (ADR-015 §4-5): registering with a deleted address mails a
 * signed link, the recovery code opens it, and the same account returns with
 * its key, documents and closure record.
 */
final class AccountRestoreTest extends AuthWebTestCase
{
    use AccountFixtures;

    public function testRegisteringADeletedAddressRestoresTheSameAccount(): void
    {
        $user = $this->signerUser('leaver');
        $email = $user->getEmail();
        $id = $user->getId()->toRfc4122();
        $document = $this->upload($user);
        $this->signer()->sign($document, $this->certificateOf($user), $user, self::PIN);
        $kek = $this->get(KeyManagementService::class)->userKek($user);
        $code = $this->get(AccountDeleter::class)->delete($user)->recoveryCode;

        $link = $this->registerAgain(strtoupper($email));
        self::assertNull($this->get(UserRepository::class)->findOneByEmail($email), 'no second account beside the archive');

        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();

        $this->submitRestore($link, 'AAAAA-BBBBB-CCCCC-DDDDD');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="restore_account_form"]', 'That recovery code is not correct.');
        self::assertTrue($this->reload($id)->isDeleted());

        $this->submitRestore($link, strtolower($code));
        self::assertResponseRedirects('/login');

        $restored = $this->reload($id);
        self::assertFalse($restored->isDeleted());
        self::assertSame(strtoupper($email), $restored->getEmail());
        self::assertSame('Ana Petrova', $restored->getFullName());
        self::assertNull($restored->getEmailFingerprint());
        self::assertFalse($restored->isTotpEnabled());
        self::assertSame($kek, $this->get(KeyManagementService::class)->userKek($restored), 'the same KEK, so every grant still opens');
        self::assertNull($this->get(ArchivedUserKeyRepository::class)->findForUser($restored));

        $document = $this->get(DocumentRepository::class)->find($document->getId());
        self::assertNotNull($document?->getLatestVersion());
        self::assertStringEndsWith('% signed', $this->get(DocumentDownloader::class)->download($document->getLatestVersion(), $restored));

        $records = $this->get(AccountClosureRecordRepository::class)->findForUser($restored);
        self::assertCount(1, $records);
        self::assertStringStartsWith('%PDF', $this->get(ClosureRecordSealer::class)->read($records[0], $restored));
        self::assertCount(1, $this->get(AuditLogEntryRepository::class)->findBy(['action' => 'account.restored', 'subjectId' => $id]));

        // The link is spent, and the account starts over with TOTP enrolment.
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/register');
        $this->submitLogin(strtoupper($email), self::PASSWORD.'-new');
        $this->client->followRedirect();
        self::assertResponseRedirects('/2fa/setup');
    }

    public function testATamperedLinkIsRefused(): void
    {
        $user = $this->createUser($email = $this->uniqueEmail('leaver'));
        $this->get(AccountDeleter::class)->delete($user);
        $link = $this->registerAgain($email);

        $this->client->request('GET', str_replace(rawurlencode($email), rawurlencode('thief@example.com'), $link));
        self::assertResponseRedirects('/register');

        $this->client->request('GET', preg_replace('/_hash=[^&]+/', '_hash=forged', $link) ?? '');
        self::assertResponseRedirects('/register');
    }

    public function testALostCodeIsReplacedFromTheConsole(): void
    {
        $user = $this->createUser($email = $this->uniqueEmail('leaver'));
        $id = $user->getId()->toRfc4122();
        $old = $this->get(AccountDeleter::class)->delete($user)->recoveryCode;

        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('sigil:account:reissue-recovery'));
        self::assertSame(Command::SUCCESS, $tester->execute(['email' => $email]));
        self::assertStringNotContainsString($old, $tester->getDisplay());

        // The last one: the deletion mail, with the old code, went out in this kernel too.
        $messages = self::getMailerMessages();
        $mail = end($messages);
        self::assertInstanceOf(TemplatedEmail::class, $mail);
        self::assertSame(1, preg_match('/[A-Z2-7]{5}(-[A-Z2-7]{5}){3}/', (string) $mail->getHtmlBody(), $m));
        $new = $m[0] ?? '';
        self::assertStringNotContainsString($new, $tester->getDisplay(), 'the code is never printed');
        self::assertCount(1, $this->get(AuditLogEntryRepository::class)->findBy(['action' => 'account.recovery_reissued', 'subjectId' => $id]));

        $link = $this->registerAgain($email);
        $this->submitRestore($link, $old);
        self::assertResponseStatusCodeSame(422);
        $this->submitRestore($link, $new);
        self::assertResponseRedirects('/login');
    }

    public function testAnUnknownAddressGetsNoNewCode(): void
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('sigil:account:reissue-recovery'));

        self::assertSame(Command::FAILURE, $tester->execute(['email' => $this->uniqueEmail('nobody')]));
    }

    /** Registers with $email and returns the restore link it mails. */
    private function registerAgain(string $email): string
    {
        $crawler = $this->client->request('GET', '/register');
        $this->client->submit($crawler->filter('form')->form([
            'registration_form[firstName]' => 'Someone',
            'registration_form[lastName]' => 'Else',
            'registration_form[email]' => $email,
            'registration_form[password][first]' => self::PASSWORD,
            'registration_form[password][second]' => self::PASSWORD,
        ]));

        self::assertResponseRedirects('/login');
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $mail);
        self::assertSame($email, $mail->getTo()[0]->getAddress());
        self::assertSame(1, preg_match('#href="(https?://[^"]*/account/restore\?[^"]*)"#', (string) $mail->getHtmlBody(), $m));

        return html_entity_decode($m[1] ?? '');
    }

    private function submitRestore(string $link, string $code): void
    {
        $crawler = $this->client->request('GET', $link);
        $this->client->submit($crawler->selectButton('Restore my account')->form([
            'restore_account_form[recoveryCode]' => $code,
            'restore_account_form[firstName]' => 'Ana',
            'restore_account_form[lastName]' => 'Petrova',
            'restore_account_form[password][first]' => self::PASSWORD.'-new',
            'restore_account_form[password][second]' => self::PASSWORD.'-new',
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
