<?php

declare(strict_types=1);

namespace App\Notification\Service;

use App\Core\Entity\User;

/**
 * The Mercure topic one person's inbox is announced on.
 *
 * Defined once because two sides have to agree on it exactly: Notifier
 * publishes to it, and MercureCookieSubscriber signs a cookie granting exactly
 * this one topic and no other. A mismatch is silent - the push simply never
 * arrives - so neither side may spell it inline.
 */
final class InboxTopic
{
    public static function for(User $user): string
    {
        return \sprintf('/users/%s/notifications', $user->getId()->toRfc4122());
    }
}
