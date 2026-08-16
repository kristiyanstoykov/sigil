<?php

declare(strict_types=1);

namespace App\Delivery\Event;

use App\Delivery\Entity\Delivery;

/**
 * A document has been served on its recipients. Receipt subscribes to seal the
 * proof; Delivery must not depend on Receipt, exactly as Signing does not.
 */
final readonly class DocumentDelivered
{
    public function __construct(public Delivery $delivery)
    {
    }
}
