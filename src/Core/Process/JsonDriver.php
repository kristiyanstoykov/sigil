<?php

declare(strict_types=1);

namespace App\Core\Process;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * The one way PHP talks to a bin/*.py driver: a JSON request on stdin, a JSON
 * response on stdout of the shape {"ok": true, ...} or {"ok": false, "error":
 * "<type>"}. Secrets (PINs, key bytes) travel only through that pipe - never
 * argv, which is world-readable in /proc.
 */
final class JsonDriver
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/bin')]
        private readonly string $binDir,
    ) {
    }

    /**
     * @param string               $script  file name under bin/, e.g. "sign_pdf.py"
     * @param array<string, mixed> $request
     * @param int                  $timeout seconds
     *
     * @return array<string, mixed> the decoded response, ok already checked
     *
     * @throws DriverException when the driver reports an error or produces no parseable output
     */
    public function run(string $script, #[\SensitiveParameter] array $request, int $timeout = 30): array
    {
        $process = new Process(['python3', $this->binDir.'/'.$script]);
        $process->setInput(json_encode($request, \JSON_THROW_ON_ERROR));
        $process->setTimeout($timeout);
        $process->run();

        /** @var mixed $decoded */
        $decoded = json_decode($process->getOutput(), true);
        if (!\is_array($decoded) || true !== ($decoded['ok'] ?? false)) {
            $error = \is_array($decoded) && \is_string($decoded['error'] ?? null) ? $decoded['error'] : 'NoOutput';

            throw new DriverException($script, $error);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
