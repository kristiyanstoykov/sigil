<?php

declare(strict_types=1);

namespace App\Certificate\Enum;

/**
 * The presentation side of a certificate's state - what the UI shows, as
 * opposed to CertificateStatus, which is what the DB stores. Adds the two
 * virtual states that never hit the DB: OnHold (Active with heldUntil in the
 * future) and Expiring (Active, notAfter close - dashboard nudge).
 *
 * Labels and Tailwind classes live here so every template renders a status
 * through one vocabulary instead of its own ternary chain. The class strings
 * are picked up by the utility build because app.css scans src/
 * (@source "../../src") - keep them as complete literals.
 */
enum CertificateDisplayStatus: string
{
    case Active = 'active';
    case OnHold = 'on_hold';
    case Locked = 'locked';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Expiring = 'expiring';

    public static function fromStatus(CertificateStatus $status): self
    {
        return self::from($status->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnHold => 'On hold',
            self::Locked => 'Locked',
            self::Revoked => 'Revoked',
            self::Expired => 'Expired',
            self::Expiring => 'Expiring',
        };
    }

    /** Badge chip: wash + text color. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Active => 'bg-success-500/10 text-success-600',
            self::OnHold => 'bg-info-500/10 text-info-600',
            self::Locked => 'bg-danger-500/10 text-danger-600',
            self::Revoked => 'bg-secondary-500/10 text-secondary-600',
            self::Expired, self::Expiring => 'bg-warning-500/10 text-warning-600',
        };
    }

    /** The status dot inside a badge. */
    public function dotClass(): string
    {
        return match ($this) {
            self::Active => 'bg-success-500',
            self::OnHold => 'bg-info-500',
            self::Locked => 'bg-danger-500',
            self::Revoked => 'bg-secondary-400',
            self::Expired, self::Expiring => 'bg-warning-500',
        };
    }

    /** The accent bar across the top of certificate cards. */
    public function accentClass(): string
    {
        return match ($this) {
            self::Active => 'bg-success-500',
            self::OnHold => 'bg-info-500',
            self::Locked => 'bg-danger-500',
            self::Expired, self::Expiring => 'bg-warning-500',
            self::Revoked => 'bg-secondary-300',
        };
    }

    /**
     * Whether the PIN gate is still alive for this certificate - lifecycle
     * actions (revoke, hold, resume) ask for the PIN exactly in these states.
     */
    public function isPinGuarded(): bool
    {
        return match ($this) {
            self::Active, self::OnHold, self::Expiring => true,
            self::Locked, self::Revoked, self::Expired => false,
        };
    }

    // Twig-friendly predicates: {% if status.onHold %} etc.

    public function isActive(): bool
    {
        return self::Active === $this;
    }

    public function isOnHold(): bool
    {
        return self::OnHold === $this;
    }

    public function isLocked(): bool
    {
        return self::Locked === $this;
    }

    public function isRevoked(): bool
    {
        return self::Revoked === $this;
    }

    public function isExpired(): bool
    {
        return self::Expired === $this;
    }

    public function isExpiring(): bool
    {
        return self::Expiring === $this;
    }
}
