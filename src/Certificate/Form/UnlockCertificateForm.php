<?php

declare(strict_types=1);

namespace App\Certificate\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * ADR-008 unlock: password AND a fresh TOTP code, both re-proven at unlock
 * time - an active session is not sufficient.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class UnlockCertificateForm extends AbstractType
{
    public const E_PASSWORD = 'password';
    public const E_TOTP_CODE = 'totpCode';

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
                'attr' => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'maxlength' => 6, 'placeholder' => '6-digit code'],
                'constraints' => [
                    new NotBlank(message: 'Please enter the 6-digit code.'),
                    new Regex(pattern: '/^\d{6}$/', message: 'The code must be 6 digits.'),
                ],
            ]);
    }
}
