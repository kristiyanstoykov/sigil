<?php

declare(strict_types=1);

namespace App\Receipt\Enum;

use App\Signing\Enum\SigningRequestStatus;

/**
 * How the thing a receipt attests ended. Wider than SigningRequestStatus because
 * a receipt can also attest a plain delivery, which has no lifecycle at all - it
 * is made and it is done.
 */
enum ReceiptOutcome: string
{
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Declined = 'declined';

    /** Served on its recipients. The only outcome a delivery can have. */
    case Delivered = 'delivered';

    public static function fromSigningRequest(SigningRequestStatus $status): self
    {
        return match ($status) {
            SigningRequestStatus::Completed => self::Completed,
            SigningRequestStatus::Expired => self::Expired,
            SigningRequestStatus::Cancelled => self::Cancelled,
            SigningRequestStatus::Declined => self::Declined,
            // A receipt is only sealed once the request is closed, so Pending
            // cannot reach here.
            SigningRequestStatus::Pending => self::Cancelled,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Declined => 'Declined',
            self::Delivered => 'Delivered',
        };
    }
}
