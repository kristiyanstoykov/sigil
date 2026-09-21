<?php

declare(strict_types=1);

namespace App\Certificate\Service;

use App\Certificate\Algorithm\SignatureAlgorithmInterface;
use App\Core\Exception\DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Thin shell-out wrapper around pkcs11-tool and the bin/ drivers (ADR-005,
 * ADR-014). The module is kryoptic: one PKCS#11 token per certificate, each a
 * `[[slots]]` block in KRYOPTIC_CONF managed by bin/kryoptic_slots.py.
 *
 * PINs are passed to child processes via environment references (pkcs11-tool's
 * `env:` syntax) or stdin JSON - NEVER as argv, which is world-readable in
 * /proc. The SO PIN is random and discarded at init time on purpose: a
 * server-held SO PIN could reset User PINs, which ADR-008 explicitly rejects.
 * Locked/forgotten PIN ⇒ delete token, re-issue.
 */
class Pkcs11TokenManager
{
    private const int TIMEOUT_SECONDS = 30;

    public function __construct(
        #[Autowire(env: 'PKCS11_MODULE')]
        private readonly string $modulePath,
        #[Autowire('%kernel.project_dir%/bin')]
        private readonly string $binDir = __DIR__.'/../../../bin',
    ) {
    }

    /**
     * Initializes a fresh token with the given User PIN: a slot is allocated in
     * the module config first, then initialised. A failure takes the slot back
     * so the config never lists a token that does not exist.
     * The generated SO PIN is intentionally thrown away.
     */
    public function initToken(string $tokenLabel, #[\SensitiveParameter] string $userPin): void
    {
        $soPin = bin2hex(random_bytes(16));
        $slot = $this->slots('add', $tokenLabel);

        try {
            $this->run([
                'pkcs11-tool', '--module', $this->modulePath,
                '--init-token', '--slot', $slot,
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
        } catch (\Throwable $e) {
            $this->slots('remove', $tokenLabel);
            throw $e;
        }
    }

    /**
     * Generates the suite's keypair inside the token (never exportable), via
     * bin/keygen.py - one path for every family, since pkcs11-tool cannot
     * generate ML-DSA keys.
     */
    public function generateKeyPair(
        string $tokenLabel,
        SignatureAlgorithmInterface $algorithm,
        string $keyLabel,
        string $keyId,
        #[\SensitiveParameter] string $userPin,
    ): void {
        $process = new Process(['python3', $this->binDir.'/keygen.py']);
        $process->setInput(json_encode([
            'module' => $this->modulePath,
            'token_label' => $tokenLabel,
            'key_label' => $keyLabel,
            'key_id' => $keyId,
            'pin' => $userPin,
            'algorithm' => $algorithm->toDriverSpec(),
        ], \JSON_THROW_ON_ERROR));
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        /** @var mixed $decoded */
        $decoded = json_decode($process->getOutput(), true);
        if (!\is_array($decoded) || true !== ($decoded['ok'] ?? false)) {
            $error = \is_array($decoded) && \is_string($decoded['error'] ?? null) ? $decoded['error'] : 'no output';
            throw new DomainException('UnsupportedAlgorithm' === $error
                ? sprintf('The token does not support %s.', $algorithm->label())
                : sprintf('Key generation failed (%s).', $error));
        }
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
     * Destroys the token and every key in it (revoke / re-issue path): the
     * slot leaves the config and its database is deleted.
     * Idempotent: a token that is already gone is the outcome wanted.
     */
    public function deleteToken(string $tokenLabel): void
    {
        $this->slots('remove', $tokenLabel);
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
     * bin/kryoptic_slots.py: the one editor of the module's slot list.
     *
     * @return string the slot id for "add"; empty otherwise
     */
    private function slots(string $command, string $tokenLabel): string
    {
        $process = new Process(['python3', $this->binDir.'/kryoptic_slots.py', $command, $tokenLabel]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new DomainException(sprintf('PKCS#11 slot registry "%s" failed (exit %d).', $command, $process->getExitCode() ?? -1));
        }

        return trim($process->getOutput());
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
