<?php

declare(strict_types=1);

namespace App\Signing\Event;

use App\Signing\Entity\SigningRequest;

/**
 * A signature request reached a terminal state (completed, expired, cancelled).
 *
 * Exists so Signing does not have to know about Receipt: the receipt is sealed
 * by a subscriber on this event. Dispatched after the closing audit entry is
 * written, so a subscriber reading the audit trail sees the whole episode.
 */
final readonly class SigningRequestClosed
{
    public function __construct(public SigningRequest $request)
    {
    }
}
