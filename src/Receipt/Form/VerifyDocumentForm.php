<?php

declare(strict_types=1);

namespace App\Receipt\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Is this file the one this receipt is about?" - one PDF, checked against the
 * receipt's fingerprint by ReceiptVerifier. No File constraint, for the same
 * reason as UploadDocumentForm: an oversized body never reaches the form, and
 * the verifier is the one authority on size. The CSRF id is per receipt so a
 * token minted for one cannot be replayed against another.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class VerifyDocumentForm extends AbstractType
{
    public const E_FILE = 'file';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_FILE, FileType::class, [
            'label' => 'The PDF you were given',
            'mapped' => false,
            'required' => true,
            'attr' => ['accept' => 'application/pdf,.pdf'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('csrf_token_id');
    }
}
