<?php

declare(strict_types=1);

namespace App\Document\Enum;

/**
 * What a document's lifecycle state looks like in the UI, as opposed to
 * {@see DocumentVersionKind}, which describes a single version. A document is
 * a Draft until it carries a Sigil signature: stored and encrypted is not the
 * finished state, it is the halfway one.
 *
 * The SigningRequest workflow (ADR-007) will add the states that sit between
 * the two - a request sent and pending, a deadline missed - as cases here, so
 * templates keep asking the document for its status instead of reconstructing
 * it from versions plus requests.
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

    /** Carries at least one signature Sigil minted. */
    case Signed = 'signed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Signed => 'Signed',
        };
    }

    /** The one-line explanation shown beside the badge on a detail page. */
    public function hint(): string
    {
        return match ($this) {
            self::Draft => 'Not signed yet - sign it yourself, or request a signature from someone else.',
            self::Signed => 'Signed and sealed. Every version is kept as a tamper-evident record.',
        };
    }

    /** Badge chip: wash + text color. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'border-warning-500/20 bg-warning-500/10 text-warning-600',
            self::Signed => 'border-success-500/20 bg-success-500/10 text-success-600',
        };
    }

    /** The status dot inside a badge. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-warning-500',
            self::Signed => 'bg-success-500',
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
            self::Signed => 'ti-circle-check',
        };
    }

    // Twig-friendly predicates: {% if status.draft %} etc.

    public function isDraft(): bool
    {
        return self::Draft === $this;
    }

    public function isSigned(): bool
    {
        return self::Signed === $this;
    }
}
