<?php

declare(strict_types=1);

namespace App\Auth\Form;

use App\Core\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationForm extends AbstractType
{
    public const E_FIRST_NAME = 'firstName';
    public const E_LAST_NAME = 'lastName';
    public const E_EMAIL = 'email';
    public const E_PASSWORD = 'password';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::E_FIRST_NAME, TextType::class, [
                'label' => 'First name',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 100),
                ],
            ])
            ->add(self::E_LAST_NAME, TextType::class, [
                'label' => 'Last name',
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 100),
                ],
            ])
            ->add(self::E_EMAIL, EmailType::class, [
                'label' => 'Email address',
                'constraints' => [
                    new NotBlank(),
                ],
            ])
            ->add(self::E_PASSWORD, RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'Password'],
                'second_options' => ['label' => 'Confirm password'],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 10, max: 4096),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
