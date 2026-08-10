<?php

declare(strict_types=1);

namespace App\Auth\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Enrollment confirm: the 6-digit TOTP code proving the authenticator app was
 * set up. This is OUR form - scheb owns the /2fa login form, not this one - so
 * it goes through the Form component like everything else we own.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class TwoFactorSetupForm extends AbstractType
{
    public const E_CODE = 'code';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_CODE, TextType::class, [
            'label' => false,
            'mapped' => false,
            'attr' => [
                'inputmode' => 'numeric',
                'autocomplete' => 'one-time-code',
                'maxlength' => 6,
                'placeholder' => '000000',
                'autofocus' => 'autofocus',
                'aria-label' => 'Six-digit code',
            ],
            'constraints' => [
                new NotBlank(message: 'Enter the 6-digit code from your app.'),
                new Length(exactly: 6, exactMessage: 'The code is 6 digits.'),
                new Regex(pattern: '/^\d{6}$/', message: 'The code is 6 digits.'),
            ],
        ]);
    }
}
