<?php

declare(strict_types=1);

namespace App\Auth\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * "Withdraw this invitation" - no fields, so its whole job is CSRF. Built per
 * row by AllowlistRemoveFormFactory with its own token id.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class AllowlistRemoveForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }
}
