<?php

declare(strict_types=1);

namespace App\Auth\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class AllowlistAddForm extends AbstractType
{
    public const E_EMAIL = 'email';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_EMAIL, EmailType::class, [
            'label' => 'Email address',
            'mapped' => false,
            'constraints' => [
                new NotBlank(),
                new Email(),
                new Length(max: 180),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'allowlist-add']);
    }
}
