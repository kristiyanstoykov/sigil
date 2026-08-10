<?php

declare(strict_types=1);

namespace App\Document\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "Share this document with" - one email address. Unmapped; the controller
 * resolves it to a User. CSRF protection comes for free (Symfony forms), and
 * "no such account" is reported as a field error rather than a flash, so the
 * message sits under the input the user has to correct.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class ShareDocumentForm extends AbstractType
{
    public const E_EMAIL = 'email';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_EMAIL, EmailType::class, [
            'label' => false,
            'mapped' => false,
            'attr' => [
                'placeholder' => 'colleague@example.com',
                'autocomplete' => 'off',
                'aria-label' => 'Email address',
            ],
            'constraints' => [
                new NotBlank(message: 'Enter the email address to share with.'),
                new Email(message: 'That does not look like an email address.'),
            ],
        ]);
    }
}
