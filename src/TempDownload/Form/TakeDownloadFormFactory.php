<?php

declare(strict_types=1);

namespace App\TempDownload\Form;

use App\TempDownload\Entity\TempDownload;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * One form per file: a page lists every part of an export, so each needs its
 * own name and its own CSRF token id (same reasoning as DeclineFormFactory).
 */
final readonly class TakeDownloadFormFactory
{
    public function __construct(private FormFactoryInterface $forms) {}

    /**
     * @return FormInterface<mixed>
     */
    public function create(TempDownload $download): FormInterface
    {
        return $this->forms->createNamed(
            'take_download_'.substr($download->getId()->toBase32(), 0, 12),
            TakeDownloadForm::class,
            null,
            ['csrf_token_id' => self::tokenId($download)],
        );
    }

    public static function tokenId(TempDownload $download): string
    {
        return 'take-download-'.$download->getId()->toRfc4122();
    }
}
