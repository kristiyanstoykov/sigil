<?php

declare(strict_types=1);

namespace App\Document\Enum;

/**
 * What a document's lifecycle state looks like in the UI, as opposed to
 * {@see DocumentVersionKind}, which describes a single version. A document is
 * a Draft until it carries a Sigil signature: stored and encrypted is not the
 * finished state, it is the halfway one.
 *
 * Pending and Expired come from a SigningRequest, which the Document module
 * cannot see; resolve those through App\Signing\Service\DocumentStatusResolver
 * rather than Document::getDisplayStatus().
 *
 * Labels and Tailwind classes live here, same as CertificateDisplayStatus:
 * one vocabulary per status, and the class strings are picked up by the
 * utility build because app.css scans src/ (@source "../../src") - keep them
 * as complete literals.
 */
enum DocumentDisplayStatus: string
{
    /** Uploaded and encrypted, but nobody has signed it yet. */
    case Draft = 'draft';

    /** A signature request is out and the signers have not all signed yet. */
    case Pending = 'pending';

    /** Carries at least one signature Sigil minted. */
    case Signed = 'signed';

    /** A request's deadline passed with signatures still missing. */
    case Expired = 'expired';

    /** A signer refused. The request is closed and nobody after them was asked. */
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Pending => 'Awaiting signatures',
            self::Signed => 'Signed',
            self::Expired => 'Expired',
            self::Declined => 'Declined',
        };
    }

    /** The one-line explanation shown beside the badge on a detail page. */
    public function hint(): string
    {
        return match ($this) {
            self::Draft => 'Not signed yet - sign it yourself, or request a signature from someone else.',
            self::Pending => 'Sent for signature. Each signer signs in turn, and nobody can jump the queue.',
            self::Signed => 'Signed and sealed. Every version is kept as a tamper-evident record.',
            self::Expired => 'The signing deadline passed before everyone signed. Signed versions are kept.',
            self::Declined => 'A signer declined. Nobody after them was asked, and signed versions are kept.',
        };
    }

    /** Badge chip: wash + text color. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'border-warning-500/20 bg-warning-500/10 text-warning-600',
            self::Pending => 'border-info-500/20 bg-info-500/10 text-info-600',
            self::Signed => 'border-success-500/20 bg-success-500/10 text-success-600',
            self::Expired => 'border-danger-500/20 bg-danger-500/10 text-danger-600',
            self::Declined => 'border-danger-500/20 bg-danger-500/10 text-danger-600',
        };
    }

    /** The status dot inside a badge. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-warning-500',
            self::Pending => 'bg-info-500',
            self::Signed => 'bg-success-500',
            self::Expired => 'bg-danger-500',
            self::Declined => 'bg-danger-500',
        };
    }

    /**
     * Icon for the badge / callout. Tabler names only - and only ones the
     * vendored subset in assets/able-pro/fonts/tabler-icons.css actually
     * carries, or the glyph renders as tofu.
     */
    public function iconClass(): string
    {
        return match ($this) {
            self::Draft => 'ti-writing-sign',
            self::Pending => 'ti-clock',
            self::Signed => 'ti-circle-check',
            self::Expired => 'ti-alert-triangle',
            self::Declined => 'ti-ban',
        };
    }

    // Twig-friendly predicates: {% if status.draft %} etc.

    public function isDraft(): bool
    {
        return self::Draft === $this;
    }

    public function isPending(): bool
    {
        return self::Pending === $this;
    }

    public function isSigned(): bool
    {
        return self::Signed === $this;
    }

    public function isExpired(): bool
    {
        return self::Expired === $this;
    }

    public function isDeclined(): bool
    {
        return self::Declined === $this;
    }
}
