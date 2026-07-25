<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use App\Core\Crypto\Exception\DecryptionFailedException;
use App\Core\Exception\DomainException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Root-key wrapper backed by a PKCS#11 token (ADR-010). The root wrapping key
 * is a non-exportable AES-256 secret key that lives INSIDE the token
 * (SoftHSM in dev, a real HSM/KMS in prod - same interface). It never enters
 * application memory; the app only ever asks the token to wrap/unwrap a single
 * KEK per call. An attacker with app-code execution can therefore only use the
 * token as an online oracle (auditable, rate-limitable) - they cannot exfiltrate
 * the root key and bulk-decrypt offline, which the env root key allowed.
 *
 * Wrap/unwrap runs in a short-lived Python driver (bin/wrap_key.py) over the
 * established PKCS#11 shell-out pattern (ADR-005). The token PIN and the raw KEK
 * cross only via the child's stdin/stdout pipes as base64 JSON - never argv
 * (world-readable in /proc), never disk.
 *
 * Storage framing: the token produces a raw `nonce || ciphertext‖tag` blob; this
 * class prepends a scheme byte (0x02) so a token-wrapped KEK is self-describing
 * and cannot be confused with an env-wrapped one ({@see EnvRootKeyWrapper}, 0x01).
 */
final class Pkcs11RootKeyWrapper implements RootKeyWrapperInterface
{
    /** Scheme marker for token-wrapped KEKs; distinguishes them from env 0x01 blobs. */
    private const SCHEME_BYTE = "\x02";

    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        #[Autowire(env: 'PKCS11_MODULE')]
        private readonly string $modulePath,
        #[Autowire(env: 'SIGIL_ROOT_TOKEN_LABEL')]
        private readonly string $tokenLabel,
        #[Autowire(env: 'SIGIL_ROOT_KEY_LABEL')]
        private readonly string $keyLabel,
        #[Autowire(env: 'SIGIL_ROOT_TOKEN_PIN')]
        private readonly string $pin,
        #[Autowire('%kernel.project_dir%/bin/wrap_key.py')]
        private readonly string $driverPath,
    ) {
    }

    public function wrapKek(#[\SensitiveParameter] string $rawKek, string $aad): string
    {
        $blob = $this->runDriver('wrap', $rawKek, $aad);

        return self::SCHEME_BYTE.$blob;
    }

    public function unwrapKek(string $wrappedKek, string $aad): string
    {
        if ('' === $wrappedKek || self::SCHEME_BYTE !== $wrappedKek[0]) {
            // Not a token-wrapped blob (or tampered) - refuse rather than feed a
            // foreign scheme to the token.
            throw new DecryptionFailedException();
        }

        try {
            return $this->runDriver('unwrap', substr($wrappedKek, 1), $aad);
        } catch (DomainException) {
            throw new DecryptionFailedException();
        }
    }

    /**
     * Create the non-exportable root wrapping key in the token if absent.
     * Idempotent - returns true if it created the key, false if it already existed.
     * Called by `sigil:root-key:init` after the token itself is provisioned.
     */
    public function provisionKey(): bool
    {
        $response = $this->invoke([
            'mode' => 'init',
            'module' => $this->modulePath,
            'token_label' => $this->tokenLabel,
            'key_label' => $this->keyLabel,
            'pin' => $this->pin,
        ]);

        return true === ($response['created'] ?? false);
    }

    /** Run a wrap/unwrap op and return the raw resulting bytes. */
    private function runDriver(string $mode, #[\SensitiveParameter] string $data, string $aad): string
    {
        $response = $this->invoke([
            'mode' => $mode,
            'module' => $this->modulePath,
            'token_label' => $this->tokenLabel,
            'key_label' => $this->keyLabel,
            'pin' => $this->pin,
            'aad' => base64_encode($aad),
            'data' => base64_encode($data),
        ]);

        $out = base64_decode((string) ($response['data'] ?? ''), true);
        if (false === $out) {
            throw new DomainException('PKCS#11 root-key driver returned malformed data.');
        }

        return $out;
    }

    /**
     * @param array<string, string> $request
     *
     * @return array<string, mixed>
     */
    private function invoke(#[\SensitiveParameter] array $request): array
    {
        $process = new Process(['python3', $this->driverPath]);
        $process->setInput(json_encode($request, \JSON_THROW_ON_ERROR));
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        /** @var mixed $decoded */
        $decoded = json_decode($process->getOutput(), true);
        if (!\is_array($decoded) || true !== ($decoded['ok'] ?? false)) {
            // The driver reports only an exception class name, never input - safe
            // to surface for diagnostics without leaking the PIN or key bytes.
            $error = \is_array($decoded) && \is_string($decoded['error'] ?? null)
                ? $decoded['error']
                : 'no output';
            throw new DomainException(sprintf('PKCS#11 root-key operation failed (%s).', $error));
        }

        return $decoded;
    }
}
