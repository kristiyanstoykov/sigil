<?php

declare(strict_types=1);

namespace App\Certificate\Twig;

use App\Certificate\Algorithm\SignatureAlgorithmRegistry;
use App\Certificate\Entity\Certificate;
use App\Core\Exception\DomainException;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * certificate_algorithm_label(cert): the human name of the suite a certificate
 * was issued with, read from its stored algorithm id. An id the registry no
 * longer knows prints itself instead of taking the page down.
 */
final class AlgorithmExtension extends AbstractExtension
{
    public function __construct(private readonly SignatureAlgorithmRegistry $algorithms)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('certificate_algorithm_label', $this->label(...)),
        ];
    }

    public function label(Certificate $certificate): string
    {
        $id = $certificate->getAlgorithmId();

        try {
            return $this->algorithms->get($id)->label();
        } catch (DomainException) {
            return $id;
        }
    }
}
