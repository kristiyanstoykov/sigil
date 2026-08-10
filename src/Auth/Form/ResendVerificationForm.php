<?php

declare(strict_types=1);

namespace App\Auth\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "Send me the confirmation link again" - one email address.
 *
 * The response must stay identical whether or not the account exists (see
 * AuthController::resendVerification), so validation here is limited to the
 * shape of the address. Nothing in this form may reveal whether it is
 * registered.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class ResendVerificationForm extends AbstractType
{
    public const E_EMAIL = 'email';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_EMAIL, EmailType::class, [
            'label' => false,
            'mapped' => false,
            'attr' => [
                'placeholder' => 'you@example.com',
                'autocomplete' => 'email',
                'aria-label' => 'Email address',
            ],
            'constraints' => [
                new NotBlank(message: 'Enter your email address.'),
                new Email(message: 'That does not look like an email address.'),
            ],
        ]);
    }
}
