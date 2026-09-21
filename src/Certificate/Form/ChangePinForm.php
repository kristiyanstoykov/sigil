<?php

declare(strict_types=1);

namespace App\Certificate\Form;

use App\Certificate\Service\PinPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class ChangePinForm extends AbstractType
{
    public const E_CURRENT_PIN = 'currentPin';
    public const E_NEW_PIN = 'newPin';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::E_CURRENT_PIN, PasswordType::class, [
                'label' => 'Current PIN',
                'mapped' => false,
                'attr' => ['autocomplete' => 'off', 'maxlength' => PinPolicy::MAX_LENGTH],
                'constraints' => [new NotBlank(message: 'Please enter your current PIN.')],
            ])
            ->add(self::E_NEW_PIN, RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'New PIN',
                    'attr' => ['autocomplete' => 'new-password', 'maxlength' => PinPolicy::MAX_LENGTH],
                ],
                'second_options' => [
                    'label' => 'Confirm new PIN',
                    'attr' => ['autocomplete' => 'new-password', 'maxlength' => PinPolicy::MAX_LENGTH],
                ],
                'invalid_message' => 'The PIN fields must match.',
                'mapped' => false,
                'constraints' => [
                    new NotBlank(message: 'Please choose a new PIN.'),
                    PinPolicy::constraint(),
                ],
            ]);
    }
}
