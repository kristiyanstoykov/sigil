<?php

declare(strict_types=1);

namespace App\Auth\Form;

use App\Auth\Entity\AllowlistedEmail;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * One remove form per allowlist row: its own name (unique DOM ids) and its own
 * CSRF token id, so a token for one row cannot be replayed against another.
 */
final readonly class AllowlistRemoveFormFactory
{
    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
    ) {}

    /**
     * @return FormInterface<mixed>
     */
    public function create(AllowlistedEmail $entry): FormInterface
    {
        return $this->forms->createNamed(
            'allowlist_remove_'.$entry->getId()->toBase32(),
            AllowlistRemoveForm::class,
            null,
            [
                'action' => $this->urls->generate('app_admin_allowlist_remove', ['id' => $entry->getId()->toRfc4122()]),
                'csrf_token_id' => 'allowlist-remove-'.$entry->getId()->toRfc4122(),
            ],
        );
    }
}
