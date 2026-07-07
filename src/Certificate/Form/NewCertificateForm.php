<?php

declare(strict_types=1);

namespace App\Certificate\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class NewCertificateForm extends AbstractType
{
    public const E_PIN = 'pin';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_PIN, RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'Certificate PIN',
                'attr' => ['autocomplete' => 'new-password', 'inputmode' => 'numeric', 'maxlength' => 8],
            ],
            'second_options' => [
                'label' => 'Confirm PIN',
                'attr' => ['autocomplete' => 'new-password', 'inputmode' => 'numeric', 'maxlength' => 8],
            ],
            'invalid_message' => 'The PIN fields must match.',
            'mapped' => false,
            'constraints' => [
                new NotBlank(message: 'Please choose a PIN.'),
                new Regex(pattern: '/^\d{6,8}$/', message: 'The PIN must be 6 to 8 digits.'),
            ],
        ]);
    }
}
