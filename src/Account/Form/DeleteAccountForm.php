<?php

declare(strict_types=1);

namespace App\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Deleting an account (ADR-015 §3): password and a fresh TOTP code re-proven,
 * and the address typed out, so it is never a stray click. The controller
 * checks all three.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class DeleteAccountForm extends AbstractType
{
    public const E_PASSWORD = 'password';
    public const E_TOTP_CODE = 'totpCode';
    public const E_CONFIRM_EMAIL = 'confirmEmail';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::E_PASSWORD, PasswordType::class, [
                'label' => 'Account password',
                'mapped' => false,
                'attr' => ['autocomplete' => 'current-password'],
                'constraints' => [new NotBlank(message: 'Please enter your password.')],
            ])
            ->add(self::E_TOTP_CODE, TextType::class, [
                'label' => 'Authenticator code',
                'mapped' => false,
                'attr' => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'maxlength' => 6],
                'constraints' => [
                    new NotBlank(message: 'Please enter the 6-digit code.'),
                    new Regex(pattern: '/^\d{6}$/', message: 'The code must be 6 digits.'),
                ],
            ])
            ->add(self::E_CONFIRM_EMAIL, TextType::class, [
                'label' => 'Type your email address to confirm',
                'mapped' => false,
                'attr' => ['autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off'],
                'constraints' => [new NotBlank(message: 'Please type your email address.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'delete-account']);
    }
}
