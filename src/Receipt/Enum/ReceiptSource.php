<?php

declare(strict_types=1);

namespace App\Receipt\Enum;

/**
 * What a receipt attests. Both are registered delivery in the ETSI sense, but
 * they are different acts: a signature request asks for something and delivers on
 * the way, a delivery only serves (ADR-012).
 */
enum ReceiptSource: string
{
    case SigningRequest = 'signing_request';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::SigningRequest => 'Signature request',
            self::Delivery => 'Delivery',
        };
    }

    public function isDelivery(): bool
    {
        return self::Delivery === $this;
    }
}
