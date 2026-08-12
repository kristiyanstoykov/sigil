<?php

declare(strict_types=1);

namespace App\Signing\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * "Withdraw this request" - no fields, so its whole job is CSRF. Callers pass a
 * per-request `csrf_token_id` so a token minted here cannot be replayed elsewhere.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class CancelSigningRequestForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }
}
