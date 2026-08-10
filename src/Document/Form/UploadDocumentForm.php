<?php

declare(strict_types=1);

namespace App\Document\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The upload modal's form: one PDF, plus the page to return to when the upload
 * FAILS (a success goes to the sign page instead).
 *
 * No File constraint here on purpose. A body larger than PHP's post_max_size is
 * discarded before any of this runs - no fields, no file, not even the CSRF
 * token survive - so the controller has to detect that case by hand anyway, and
 * DocumentUploader is the one authority on what a valid PDF is (magic bytes and
 * the 10 MiB limit), for the web and the CLI alike.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class UploadDocumentForm extends AbstractType
{
    public const E_FILE = 'document';
    public const E_RETURN = 'return';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::E_FILE, FileType::class, [
                'label' => false,
                'mapped' => false,
                'required' => true,
                'attr' => ['accept' => 'application/pdf,.pdf'],
            ])
            ->add(self::E_RETURN, HiddenType::class, [
                'mapped' => false,
            ]);
    }
}
