<?php

declare(strict_types=1);

namespace App\Signing\Form;

use App\Signing\Entity\SigningRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "Ask these people to sign, in this order." The signer list is a hidden,
 * newline-separated list of email addresses whose ORDER is the signing order -
 * the visible list and its reordering controls are Stimulus, and the controller
 * resolves the addresses to users.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class CreateSigningRequestForm extends AbstractType
{
    public const E_SIGNERS = 'signers';
    public const E_DEADLINE_DAYS = 'deadlineDays';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::E_SIGNERS, HiddenType::class, [
                'mapped' => false,
                // HiddenType bubbles its errors to the root form by default,
                // which would strand "X cannot sign" away from the list it is about.
                'error_bubbling' => false,
                'constraints' => [
                    new NotBlank(message: 'Add at least one signer.'),
                ],
            ])
            ->add(self::E_DEADLINE_DAYS, ChoiceType::class, [
                'label' => 'Sign within',
                'mapped' => false,
                'data' => (string) SigningRequest::DEFAULT_DEADLINE_DAYS,
                'choices' => self::deadlineChoices(),
                'constraints' => [
                    new NotBlank(message: 'Choose how long the signers have.'),
                ],
            ]);
    }

    /**
     * @return array<string, string>
     */
    private static function deadlineChoices(): array
    {
        $choices = [];
        foreach ([3, 7, 14, SigningRequest::MAX_DEADLINE_DAYS] as $days) {
            $choices[sprintf('%d days', $days)] = (string) $days;
        }

        return $choices;
    }
}
