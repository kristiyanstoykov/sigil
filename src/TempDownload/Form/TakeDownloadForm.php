<?php

declare(strict_types=1);

namespace App\TempDownload\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * "Download this file" - no fields, so its whole job is CSRF. A POST, not a
 * link: the download is spent on first use, and Turbo prefetches links on hover.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class TakeDownloadForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }
}
