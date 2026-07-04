<?php

declare(strict_types=1);

namespace App\Auth\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class ResetPasswordRequestForm extends AbstractType
{
    public const E_EMAIL = 'email';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_EMAIL, EmailType::class, [
            'label' => 'Email address',
            'attr' => ['autocomplete' => 'email', 'autofocus' => true],
            'constraints' => [
                new NotBlank(message: 'Please enter your email address.'),
            ],
        ]);
    }
}
