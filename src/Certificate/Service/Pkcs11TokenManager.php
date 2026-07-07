<?php

declare(strict_types=1);

namespace App\Certificate\Service;

use App\Core\Exception\DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Thin shell-out wrapper around pkcs11-tool / softhsm2-util (ADR-005).
 *
 * One PKCS#11 token per certificate. PINs are passed to child processes via
 * environment references (pkcs11-tool's `env:` syntax) - NEVER as argv,
 * which is world-readable in /proc. The SO PIN is random and discarded at
 * init time on purpose: a server-held SO PIN could reset User PINs, which
 * ADR-008 explicitly rejects. Locked/forgotten PIN ⇒ delete token, re-issue.
 */
class Pkcs11TokenManager
{
    private const int TIMEOUT_SECONDS = 30;

    public function __construct(
        #[Autowire(env: 'PKCS11_MODULE')]
        private readonly string $modulePath,
    ) {
    }

    /**
     * Initializes a fresh token with the given User PIN.
     * The generated SO PIN is intentionally thrown away.
     */
    public function initToken(string $tokenLabel, #[\SensitiveParameter] string $userPin): void
    {
        $soPin = bin2hex(random_bytes(16));

        $this->run([
            'pkcs11-tool', '--module', $this->modulePath,
            '--init-token', '--slot', (string) $this->findFreeSlotId(),
            '--label', $tokenLabel,
            '--so-pin', 'env:SIGIL_SO_PIN',
        ], ['SIGIL_SO_PIN' => $soPin]);

        $this->run([
            'pkcs11-tool', '--module', $this->modulePath,
            '--token-label', $tokenLabel,
            '--init-pin', '--login', '--login-type', 'so',
            '--so-pin', 'env:SIGIL_SO_PIN',
            '--new-pin', 'env:SIGIL_USER_PIN',
        ], ['SIGIL_SO_PIN' => $soPin, 'SIGIL_USER_PIN' => $userPin]);
    }

    /**
     * Generates a keypair inside the token (never exportable).
     *
     * @param string $keyType pkcs11-tool key spec, e.g. "EC:secp384r1"
     */
    public function generateKeyPair(
        string $tokenLabel,
        string $keyType,
        string $keyLabel,
        string $keyId,
        #[\SensitiveParameter] string $userPin,
    ): void {
        $this->run([
            'pkcs11-tool', '--module', $this->modulePath,
            '--token-label', $tokenLabel,
            '--login', '--pin', 'env:SIGIL_USER_PIN',
            '--keypairgen', '--key-type', $keyType,
            '--label', $keyLabel, '--id', $keyId,
        ], ['SIGIL_USER_PIN' => $userPin]);
    }

    /**
     * Stores a (public) certificate object in the token next to its key.
     */
    public function writeCertificate(
        string $tokenLabel,
        string $certificateDer,
        string $certLabel,
        string $keyId,
        #[\SensitiveParameter] string $userPin,
    ): void {
        $tmp = tempnam(sys_get_temp_dir(), 'sigil-cert-');
        if (false === $tmp) {
            throw new DomainException('Could not create temporary file for certificate.');
        }

        try {
            file_put_contents($tmp, $certificateDer);
            $this->run([
                'pkcs11-tool', '--module', $this->modulePath,
                '--token-label', $tokenLabel,
                '--login', '--pin', 'env:SIGIL_USER_PIN',
                '--write-object', $tmp, '--type', 'cert',
                '--label', $certLabel, '--id', $keyId,
            ], ['SIGIL_USER_PIN' => $userPin]);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Reads a certificate object (public, no PIN needed) back out of the
     * token as DER. Used to repair the CA cert file if var/ is wiped.
     */
    public function readCertificate(string $tokenLabel, string $certLabel): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sigil-cert-');
        if (false === $tmp) {
            throw new DomainException('Could not create temporary file for certificate.');
        }

        try {
            $this->run([
                'pkcs11-tool', '--module', $this->modulePath,
                '--token-label', $tokenLabel,
                '--read-object', '--type', 'cert', '--label', $certLabel,
                '-o', $tmp,
            ]);
            $der = file_get_contents($tmp);
            if (false === $der || '' === $der) {
                throw new DomainException('Certificate object not found in token.');
            }

            return $der;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * ADR-008 PIN change, token side. Caller updates the DB hash afterwards
     * (token first - a failed DB write is caught by the desync tripwire).
     */
    public function changeUserPin(
        string $tokenLabel,
        #[\SensitiveParameter] string $currentPin,
        #[\SensitiveParameter] string $newPin,
    ): void {
        $this->run([
            'pkcs11-tool', '--module', $this->modulePath,
            '--token-label', $tokenLabel,
            '--login', '--login-type', 'user',
            '--pin', 'env:SIGIL_USER_PIN',
            '--change-pin', '--new-pin', 'env:SIGIL_NEW_PIN',
        ], ['SIGIL_USER_PIN' => $currentPin, 'SIGIL_NEW_PIN' => $newPin]);
    }

    /**
     * Destroys the token and every key in it (revoke / re-issue path).
     */
    public function deleteToken(string $tokenLabel): void
    {
        $this->run(['softhsm2-util', '--delete-token', '--token', $tokenLabel]);
    }

    public function tokenExists(string $tokenLabel): bool
    {
        $process = new Process([
            'pkcs11-tool', '--module', $this->modulePath, '--list-token-slots',
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        return str_contains($process->getOutput(), 'token label        : '.$tokenLabel);
    }

    /**
     * SoftHSM always exposes exactly one uninitialized slot; find its id.
     */
    private function findFreeSlotId(): int
    {
        $process = new Process([
            'pkcs11-tool', '--module', $this->modulePath, '--list-slots',
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->mustRun();

        $slot = null;
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (1 === preg_match('/^Slot \d+ \((0x[0-9a-f]+)\)/', $line, $m)) {
                $slot = hexdec($m[1]);
            } elseif (null !== $slot && str_contains($line, 'uninitialized')) {
                return (int) $slot;
            }
        }

        throw new DomainException('No free PKCS#11 slot available.');
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $secretEnv PIN material - env only, never argv
     */
    private function run(array $command, array $secretEnv = []): void
    {
        $process = new Process($command, env: $secretEnv);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            // never echo command output verbatim into exceptions/logs where
            // it could carry sensitive context; keep it terse
            throw new DomainException(sprintf(
                'PKCS#11 operation "%s" failed (exit %d).',
                $command[0].' '.($command[3] ?? $command[1]),
                $process->getExitCode() ?? -1,
            ));
        }
    }
}
