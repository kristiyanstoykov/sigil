<?php

declare(strict_types=1);

namespace App\Receipt\Service;

use App\Core\Exception\DomainException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Environment;

/**
 * mPDF implementation of {@see ReceiptRendererInterface}.
 *
 * DejaVu Sans is the default font on purpose: it ships with mPDF and covers
 * Cyrillic, so a receipt naming Bulgarian signers renders without embedding
 * anything. The brand font is not used here - a receipt is an evidentiary
 * document, and a missing glyph would be a correctness bug, not a style one.
 */
final class MpdfReceiptRenderer implements ReceiptRendererInterface
{
    public function __construct(
        private readonly Environment $twig,
        #[Autowire('%kernel.cache_dir%/mpdf')]
        private readonly string $tempDir,
    ) {
    }

    public function render(string $template, array $context): string
    {
        if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0770, true) && !is_dir($this->tempDir)) {
            throw new DomainException('Could not create the PDF temp directory.');
        }

        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => 'A4',
                'default_font' => 'dejavusans',
                'default_font_size' => 9,
                'tempDir' => $this->tempDir,
                'margin_top' => 16,
                'margin_bottom' => 18,
                'margin_left' => 14,
                'margin_right' => 14,
            ]);
            $mpdf->SetTitle('Sigil delivery receipt');
            $mpdf->SetCreator('Sigil Signum Veritatis');
            $mpdf->WriteHTML($this->twig->render($template, $context));

            /** @var string */
            return $mpdf->Output('', Destination::STRING_RETURN);
        } catch (\Throwable $e) {
            throw new DomainException('Could not render the delivery receipt.', previous: $e);
        }
    }
}
