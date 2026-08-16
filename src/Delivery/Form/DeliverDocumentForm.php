<?php

declare(strict_types=1);

namespace App\Delivery\Form;

use App\Delivery\Entity\Delivery;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * "Serve this document on these people." The recipient list is a hidden,
 * newline-separated list of email addresses; unlike the signer list its ORDER
 * carries no meaning, because everyone is served at once.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class DeliverDocumentForm extends AbstractType
{
    public const E_RECIPIENTS = 'recipients';
    public const E_NOTE = 'note';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(self::E_RECIPIENTS, HiddenType::class, [
                'mapped' => false,
                // Otherwise HiddenType bubbles errors to the root form, stranding
                // "X cannot be served" away from the list it is about.
                'error_bubbling' => false,
                'constraints' => [
                    new NotBlank(message: 'Add at least one recipient.'),
                ],
            ])
            ->add(self::E_NOTE, TextareaType::class, [
                'label' => 'Covering note (optional)',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'What this is, and why they are getting it.',
                ],
                'constraints' => [
                    new Length(max: Delivery::MAX_NOTE_LENGTH),
                ],
            ]);
    }
}
