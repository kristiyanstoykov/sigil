<?php

declare(strict_types=1);

namespace App\Document\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Uuid;

/**
 * "Remove this person's access" - carries only the target user's id. A form
 * class even though there is nothing to type: it keeps CSRF and validation on
 * the component instead of in the controller, like every other form we own.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class RevokeShareForm extends AbstractType
{
    public const E_USER = 'user';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add(self::E_USER, HiddenType::class, [
            'mapped' => false,
            'constraints' => [new NotBlank(), new Uuid()],
        ]);
    }
}
