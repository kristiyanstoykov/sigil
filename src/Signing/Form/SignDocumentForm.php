<?php

declare(strict_types=1);

namespace App\Signing\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Review-and-sign form: pick one usable certificate and enter its PIN. Both
 * fields are unmapped; the controller reads them directly. CSRF protection is
 * on by default (Symfony forms) - the hand-rolled POST pitfall is avoided.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class SignDocumentForm extends AbstractType
{
    public const E_CERTIFICATE = 'certificate';
    public const E_PIN = 'pin';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::E_CERTIFICATE, ChoiceType::class, [
                'label' => 'Signing certificate',
                'choices' => $options['certificate_choices'],
                'placeholder' => false,
                'mapped' => false,
                'constraints' => [new NotBlank(message: 'Choose a certificate to sign with.')],
            ])
            ->add(self::E_PIN, PasswordType::class, [
                'label' => 'Certificate PIN',
                'mapped' => false,
                'attr' => ['autocomplete' => 'off', 'inputmode' => 'numeric', 'maxlength' => 8],
                'constraints' => [new NotBlank(message: 'Enter your certificate PIN.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['certificate_choices' => []]);
        $resolver->setAllowedTypes('certificate_choices', 'array');
    }
}
