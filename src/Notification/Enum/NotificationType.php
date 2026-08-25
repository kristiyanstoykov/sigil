<?php

declare(strict_types=1);

namespace App\Notification\Enum;

/**
 * What a notification is about. One case per moment where something happened to
 * a document and the person it concerns was not the one who did it.
 *
 * Icon and tone live here for the same reason they live on DocumentDisplayStatus:
 * one vocabulary per type, and the class strings must stay complete literals so
 * the utility build picks them up (app.css scans src/).
 */
enum NotificationType: string
{
    /** The signing queue reached you and it is your turn. */
    case SignatureRequested = 'signature_requested';

    /** Someone signed a document you own. */
    case DocumentSigned = 'document_signed';

    /** Every signer on a request you sent has signed. */
    case SigningCompleted = 'signing_completed';

    /** A request you were on ended without completing: expired, declined or withdrawn. */
    case SigningClosed = 'signing_closed';

    /** A document was served on you. Nothing is asked of you (ADR-012). */
    case DocumentDelivered = 'document_delivered';

    public function icon(): string
    {
        return match ($this) {
            self::SignatureRequested => 'ti-writing-sign',
            self::DocumentSigned, self::SigningCompleted => 'ti-circle-check',
            self::SigningClosed => 'ti-alert-triangle',
            self::DocumentDelivered => 'ti-mail-forward',
        };
    }

    /** The tinted circle behind the icon. */
    public function toneClass(): string
    {
        return match ($this) {
            self::SignatureRequested => 'bg-primary-500/10 text-primary-500',
            self::DocumentSigned, self::SigningCompleted => 'bg-success-500/10 text-success-600',
            self::SigningClosed => 'bg-danger-500/10 text-danger-600',
            self::DocumentDelivered => 'bg-info-500/10 text-info-600',
        };
    }

    /** Whether the row belongs in "needs your action" until it is read. */
    public function isActionable(): bool
    {
        return match ($this) {
            self::SignatureRequested, self::DocumentDelivered => true,
            default => false,
        };
    }
}
