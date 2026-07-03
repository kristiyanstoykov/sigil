<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Core\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Shared plumbing for Auth functional tests: user fixtures, RFC 6238 TOTP code
 * generation (so tests can pass the real scheb 2FA check), and rate-limiter
 * cache clearing so repeated suite runs don't trip login throttling.
 */
abstract class AuthWebTestCase extends WebTestCase
{
    /** Base32 secret used for TOTP test users. */
    protected const TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

    protected const PASSWORD = 'correct-horse-battery';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiters();
    }

    /** Unique email per test run so throttling/uniqueness never collide across runs. */
    protected function uniqueEmail(string $prefix = 'user'): string
    {
        return sprintf('%s+%s@test.sigil.local', $prefix, bin2hex(random_bytes(6)));
    }

    protected function createUser(
        string $email,
        bool $verified = true,
        bool $totpEnabled = false,
    ): User {
        $container = static::getContainer();
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('Signer')
            ->setRoles(['ROLE_SIGNER'])
            ->setIsVerified($verified);
        $user->setPassword($hasher->hashPassword($user, self::PASSWORD));

        if ($totpEnabled) {
            $user->setGoogleAuthenticatorSecret(self::TOTP_SECRET);
            $user->enableTotp();
        }

        $em->persist($user);
        $em->flush();

        return $user;
    }

    /** Submit the login form like a browser would (CSRF token included). */
    protected function submitLogin(string $email, string $password): void
    {
        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->filter('form')->form([
            'email' => $email,
            'password' => $password,
        ]);
        $this->client->submit($form);
    }

    /**
     * Assert the user is mid-2FA: a successful password login redirects to the
     * default target first; requesting it must then bounce to the /2fa form.
     */
    protected function assertTwoFactorInProgress(): void
    {
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseRedirects();
        self::assertStringContainsString('/2fa', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /** Current RFC 6238 TOTP code (SHA-1, 6 digits, 30s period) for a base32 secret. */
    protected function totpCode(string $base32Secret, ?int $timestamp = null): string
    {
        $key = $this->base32Decode($base32Secret);
        $counter = intdiv($timestamp ?? time(), 30);
        $binCounter = pack('N2', 0, $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3])
        ) % 1_000_000;

        return str_pad((string) $code, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $b32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(rtrim(strtoupper($b32), '=')) as $char) {
            $pos = strpos($alphabet, $char);
            self::assertNotFalse($pos, 'Invalid base32 character in TOTP secret');
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }

        return $out;
    }

    /** Login throttling + resend limits persist in the cache pool between runs. */
    private function clearRateLimiters(): void
    {
        static::getContainer()->get('cache.rate_limiter')->clear();
    }
}
