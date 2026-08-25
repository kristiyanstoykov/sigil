<?php

declare(strict_types=1);

namespace App\Notification\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * "Open this notification" - no fields, so its whole job is CSRF.
 *
 * Following a notification marks it read, which is a state change, so it is a
 * POST rather than a link: Turbo prefetches links on hover, and a GET that
 * mutates would clear the badge for anyone who merely swept the mouse past it.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class OpenNotificationForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }
}
