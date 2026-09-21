<?php

declare(strict_types=1);

namespace App\Document\Twig;

use App\Document\Service\Fingerprint;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Splits a stored fingerprint ("HMAC-SHA384/v1:<hex>") for display: the digest
 * is what people copy and compare, the algorithm is what the receipt names.
 */
final class FingerprintExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('fingerprint_digest', static fn (string $stored): string => Fingerprint::fromString($stored)->digest),
            new TwigFilter('fingerprint_algorithm', static fn (string $stored): string => Fingerprint::fromString($stored)->algorithm),
        ];
    }
}
