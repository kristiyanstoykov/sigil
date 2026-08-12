<?php

declare(strict_types=1);

namespace App\Signing\Twig;

use App\Document\Entity\Document;
use App\Document\Enum\DocumentDisplayStatus;
use App\Signing\Service\DocumentStatusResolver;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `document_status(document)` - the status badge templates should use. It knows
 * about signature requests, which `document.displayStatus` does not.
 */
final class DocumentStatusExtension extends AbstractExtension
{
    public function __construct(private readonly DocumentStatusResolver $resolver)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('document_status', $this->status(...)),
        ];
    }

    public function status(Document $document): DocumentDisplayStatus
    {
        return $this->resolver->resolve($document);
    }
}
