<?php

declare(strict_types=1);

namespace App\Auth\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordForm extends AbstractType
{
    public const E_PASSWORD = 'plainPassword';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_PASSWORD, RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'New password',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'second_options' => [
                'label' => 'Confirm new password',
                'attr' => ['autocomplete' => 'new-password'],
            ],
            'invalid_message' => 'The password fields must match.',
            'mapped' => false,
            'constraints' => [
                new NotBlank(message: 'Please enter a new password.'),
                new Length(min: 10, max: 4096, minMessage: 'Your password must be at least {{ limit }} characters.'),
            ],
        ]);
    }
}
