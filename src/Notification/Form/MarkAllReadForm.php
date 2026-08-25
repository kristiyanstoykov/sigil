<?php

declare(strict_types=1);

namespace App\Notification\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * "Mark everything read" - no fields, CSRF only.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class MarkAllReadForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }
}
