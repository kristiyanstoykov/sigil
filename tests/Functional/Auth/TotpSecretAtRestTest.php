<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Auth\Command\TotpSealCommand;
use App\Auth\Security\TotpSecretVault;
use App\Core\Crypto\Exception\DecryptionFailedException;
use App\Tests\Functional\AuthWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The second factor's seed never sits in the clear: not in the row, not in
 * the session - and the login still works, because the bundle is handed the
 * opened seed at code-check time only.
 */
final class TotpSecretAtRestTest extends AuthWebTestCase
{
    public function testTheRowHoldsAnEnvelopeAndTheLoginStillOpensIt(): void
    {
        $user = $this->createUser($this->uniqueEmail('sealed'), totpEnabled: true);

        $stored = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT google_authenticator_secret FROM users WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);
        self::assertIsString($stored);
        self::assertStringStartsWith(TotpSecretVault::PREFIX, $stored);
        self::assertStringNotContainsString(self::TOTP_SECRET, $stored);

        $this->loginFully($user->getEmail());
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }

    public function testTheSessionCopyOfAUserCarriesNeitherSeedNorPasswordHash(): void
    {
        $user = $this->createUser($this->uniqueEmail('session'), totpEnabled: true);

        $serialized = serialize($user);

        self::assertStringNotContainsString(self::TOTP_SECRET, $serialized);
        self::assertStringNotContainsString((string) $user->getGoogleAuthenticatorSecret(), $serialized, 'not even the envelope');
        self::assertStringNotContainsString((string) $user->getPassword(), $serialized, 'the hash is replaced by its crc32c');
    }

    public function testASealedSeedIsBoundToItsUser(): void
    {
        $vault = static::getContainer()->get(TotpSecretVault::class);
        $sealed = $vault->seal(self::TOTP_SECRET, 'user-a');

        self::assertSame(self::TOTP_SECRET, $vault->open($sealed, 'user-a'));
        $this->expectException(DecryptionFailedException::class);
        $vault->open($sealed, 'user-b');
    }

    public function testLegacyClearSeedsAreSealedByTheCommandAndKeepWorking(): void
    {
        $user = $this->createUser($this->uniqueEmail('legacy'), totpEnabled: true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        // A row from before the vault: the seed in the clear.
        $em->getConnection()->executeStatement('UPDATE users SET google_authenticator_secret = :s WHERE id = :id', ['s' => self::TOTP_SECRET, 'id' => $user->getId()->toRfc4122()]);
        $em->clear();

        $tester = new CommandTester(static::getContainer()->get(TotpSealCommand::class));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Sealed', $tester->getDisplay());

        $stored = $em->getConnection()->fetchOne('SELECT google_authenticator_secret FROM users WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);
        self::assertIsString($stored);
        self::assertStringStartsWith(TotpSecretVault::PREFIX, $stored);

        $this->loginFully($user->getEmail());
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
    }
}
