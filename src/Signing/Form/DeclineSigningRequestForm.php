<?php

declare(strict_types=1);

namespace App\Signing\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;

/**
 * "I will not sign this" - with an optional reason. The reason stays optional on
 * purpose: a refusal needs no justification, and forcing one would make declining
 * feel like something to be argued out of.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class DeclineSigningRequestForm extends AbstractType
{
    public const string E_REASON = 'reason';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_REASON, TextareaType::class, [
            'label' => 'Reason (optional)',
            'required' => false,
            'mapped' => false,
            'constraints' => [new Length(max: 500, maxMessage: 'Keep the reason under {{ limit }} characters.')],
            'attr' => [
                'rows' => 3,
                'maxlength' => 500,
                'placeholder' => 'Let the sender know why, if you want to.',
                'class' => 'form-control',
            ],
        ]);
    }
}
