<?php

declare(strict_types=1);

namespace App\Signing\Enum;

/**
 * Lifecycle of a signature request. Pending is the only state in which a
 * signature is accepted; the rest are terminal.
 */
enum SigningRequestStatus: string
{
    /** Sent, waiting for the signers to work through the list in order. */
    case Pending = 'pending';

    /** Every signer signed. */
    case Completed = 'completed';

    /** The deadline passed with at least one signature still missing. */
    case Expired = 'expired';

    /** Withdrawn by the requester before it completed. */
    case Cancelled = 'cancelled';

    /**
     * Refused by the signer whose turn it was. Signing is consensual - being
     * asked to sign is not being obliged to - so a refusal is a first-class
     * outcome, distinct from letting the deadline run out (ADR-012).
     */
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting signatures',
            self::Completed => 'Completed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Declined => 'Declined',
        };
    }

    public function isPending(): bool
    {
        return self::Pending === $this;
    }
}
