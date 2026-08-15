<?php

declare(strict_types=1);

namespace App\Receipt\Service;

/**
 * Turns the receipt's Twig template into PDF bytes.
 *
 * The seam that keeps the PDF engine swappable, in the same spirit as
 * {@see \App\Signing\Service\PadesSignerInterface} and
 * {@see \App\Document\Service\DocumentStorageInterface}: mPDF today, another
 * engine later, without the generator or the template moving.
 */
interface ReceiptRendererInterface
{
    /**
     * @param array<string, mixed> $context Twig variables for the template
     *
     * @return string PDF bytes
     *
     * @throws \App\Core\Exception\DomainException on a rendering failure
     */
    public function render(string $template, array $context): string;
}
