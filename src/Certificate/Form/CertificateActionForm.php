<?php

declare(strict_types=1);

namespace App\Certificate\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The PIN-confirmed lifecycle actions on a certificate: hold, resume, revoke.
 * One type, because the three differ only in wording and destination.
 *
 * `with_pin` drops the field for actions that do not need it (none today - it
 * keeps the door open without a second type). Callers MUST pass a per-action,
 * per-certificate `csrf_token_id`, e.g. 'hold-certificate-{uuid}', so a token
 * minted for one action cannot be replayed on another.
 *
 * The PIN is only shaped here (6-8 digits). Whether it is CORRECT is decided by
 * PinGate, behind the rate limiter and the Argon2id hash - never by the form.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class CertificateActionForm extends AbstractType
{
    public const E_PIN = 'pin';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!$options['with_pin']) {
            return;
        }

        $builder->add(self::E_PIN, PasswordType::class, [
            'label' => false,
            'mapped' => false,
            'attr' => [
                'autocomplete' => 'off',
                'inputmode' => 'numeric',
                'maxlength' => 8,
                'placeholder' => '••••••',
                'aria-label' => 'Certificate PIN',
            ],
            'constraints' => [
                new NotBlank(message: 'Enter your certificate PIN.'),
                new Length(min: 6, max: 8, minMessage: 'The PIN is 6-8 digits.', maxMessage: 'The PIN is 6-8 digits.'),
                new Regex(pattern: '/^\d+$/', message: 'The PIN is digits only.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['with_pin' => true]);
        $resolver->setAllowedTypes('with_pin', 'bool');
    }
}
