<?php

declare(strict_types=1);

namespace App\AuditLog\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Verify the chain now" - no fields, CSRF only. A POST because it walks the
 * whole log, which is work, not a read.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class VerifyChainForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'verify-audit-chain']);
    }
}
