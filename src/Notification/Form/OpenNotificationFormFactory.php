<?php

declare(strict_types=1);

namespace App\Notification\Form;

use App\Notification\Entity\Notification;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * One form per notification row, so the bell dropdown and the notifications page
 * can render many of them without emitting duplicate DOM ids or a single CSRF
 * token replayable against every other row on the page - the same reason
 * {@see \App\Signing\Form\DeclineFormFactory} exists.
 */
final readonly class OpenNotificationFormFactory
{
    public function __construct(private FormFactoryInterface $forms)
    {
    }

    /**
     * @return FormInterface<mixed>
     */
    public function create(Notification $notification): FormInterface
    {
        return $this->forms->createNamed(
            self::name($notification),
            OpenNotificationForm::class,
            null,
            ['csrf_token_id' => 'open-notification-'.$notification->getId()->toRfc4122()],
        );
    }

    /** Base32 of the UUID: a valid HTML name fragment, unlike the RFC 4122 form. */
    public static function name(Notification $notification): string
    {
        return 'open_'.substr($notification->getId()->toBase32(), 0, 12);
    }
}
