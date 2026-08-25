<?php

declare(strict_types=1);

namespace App\Notification\Form;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the one "mark everything read" form, for both places that show it: the
 * notifications page and the header bell.
 *
 * It exists because they drifted. The bell used to hand-roll a bare
 * `_csrf_token` field, which the Form component never looks at - the submit
 * simply did nothing and redirected. One factory means the name, the action and
 * the token id cannot disagree again.
 */
final readonly class MarkAllReadFormFactory
{
    private const string CSRF_TOKEN_ID = 'mark-all-notifications-read';

    public function __construct(
        private FormFactoryInterface $forms,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return FormInterface<mixed>
     */
    public function create(): FormInterface
    {
        return $this->forms->create(MarkAllReadForm::class, null, [
            'action' => $this->urls->generate('app_notifications_read_all'),
            'csrf_token_id' => self::CSRF_TOKEN_ID,
        ]);
    }
}
