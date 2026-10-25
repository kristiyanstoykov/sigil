<?php

declare(strict_types=1);

namespace App\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PasswordStrength;

/**
 * Restoring a deleted account (ADR-015 §4): the recovery code, then the
 * account made again - name, password - on the same id. Password rules match
 * registration.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class RestoreAccountForm extends AbstractType
{
    public const E_RECOVERY_CODE = 'recoveryCode';
    public const E_FIRST_NAME = 'firstName';
    public const E_LAST_NAME = 'lastName';
    public const E_COMPANY = 'company';
    public const E_POSITION = 'position';
    public const E_PASSWORD = 'password';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::E_RECOVERY_CODE, TextType::class, [
                'label' => 'Recovery code',
                'mapped' => false,
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'characters', 'placeholder' => 'XXXXX-XXXXX-XXXXX-XXXXX'],
                'constraints' => [new NotBlank(message: 'Please enter your recovery code.'), new Length(max: 64)],
            ])
            ->add(self::E_FIRST_NAME, TextType::class, [
                'label' => 'First name',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add(self::E_LAST_NAME, TextType::class, [
                'label' => 'Last name',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(max: 100)],
            ])
            ->add(self::E_COMPANY, TextType::class, [
                'label' => 'Company (optional)',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(max: 150)],
            ])
            ->add(self::E_POSITION, TextType::class, [
                'label' => 'Position (optional)',
                'mapped' => false,
                'required' => false,
                'constraints' => [new Length(max: 150)],
            ])
            ->add(self::E_PASSWORD, RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'New password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Confirm password', 'attr' => ['autocomplete' => 'new-password']],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 10, max: 4096),
                    new PasswordStrength(minScore: PasswordStrength::STRENGTH_MEDIUM, message: 'This password is too easy to guess - make it longer or less predictable.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'restore-account']);
    }
}
