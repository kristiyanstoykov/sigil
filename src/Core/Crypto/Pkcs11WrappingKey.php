<?php

declare(strict_types=1);

namespace App\Core\Crypto;

use App\Core\Exception\DomainException;
use App\Core\Process\DriverException;
use App\Core\Process\JsonDriver;

/**
 * One non-exportable AES-256 key inside one PKCS#11 token, driven through
 * bin/wrap_key.py. The root wrapper and the archive wrapper (ADR-015) are each
 * one of these with their own token, label and PIN; they add the scheme byte.
 */
final readonly class Pkcs11WrappingKey
{
    private const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private string $modulePath,
        private string $tokenLabel,
        private string $keyLabel,
        #[\SensitiveParameter]
        private string $pin,
        private JsonDriver $driver,
        private string $purpose,
    ) {
    }

    /** Creates the key in the token if absent; true if it was created. */
    public function provision(): bool
    {
        $response = $this->invoke(['mode' => 'init'] + $this->target());

        return true === ($response['created'] ?? false);
    }

    /** Raw `nonce || ciphertext‖tag`, without any scheme byte. */
    public function wrap(#[\SensitiveParameter] string $data, string $aad): string
    {
        return $this->run('wrap', $data, $aad);
    }

    public function unwrap(string $blob, string $aad): string
    {
        return $this->run('unwrap', $blob, $aad);
    }

    private function run(string $mode, #[\SensitiveParameter] string $data, string $aad): string
    {
        $response = $this->invoke(['mode' => $mode] + $this->target() + [
            'aad' => base64_encode($aad),
            'data' => base64_encode($data),
        ]);

        $out = base64_decode((string) ($response['data'] ?? ''), true);
        if (false === $out) {
            throw new DomainException(sprintf('PKCS#11 %s driver returned malformed data.', $this->purpose));
        }

        return $out;
    }

    /** @return array<string, string> */
    private function target(): array
    {
        return [
            'module' => $this->modulePath,
            'token_label' => $this->tokenLabel,
            'key_label' => $this->keyLabel,
            'pin' => $this->pin,
        ];
    }

    /**
     * @param array<string, string> $request
     *
     * @return array<string, mixed>
     */
    private function invoke(#[\SensitiveParameter] array $request): array
    {
        try {
            return $this->driver->run('wrap_key.py', $request, timeout: self::TIMEOUT_SECONDS);
        } catch (DriverException $e) {
            // The driver reports only an exception class name, never input.
            throw new DomainException(sprintf('PKCS#11 %s operation failed (%s).', $this->purpose, $e->error));
        }
    }
}
