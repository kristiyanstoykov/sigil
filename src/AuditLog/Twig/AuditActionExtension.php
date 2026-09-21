<?php

declare(strict_types=1);

namespace App\AuditLog\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * How an audit action reads on screen: an icon, a tone and a human label, in
 * one place for the dashboard feed and the audit page. Unknown actions fall
 * back to the raw name - a new action is still shown, just not dressed.
 *
 * Tones are full class strings on purpose: Tailwind scans templates and PHP
 * for literal utilities and would not emit an interpolated one.
 */
final class AuditActionExtension extends AbstractExtension
{
    private const string NEUTRAL = 'bg-theme-activebg text-theme-secondarytextcolor';
    private const string INFO = 'bg-info-500/10 text-info-600';
    private const string SUCCESS = 'bg-success-500/10 text-success-600';
    private const string PRIMARY = 'bg-primary-500/10 text-primary-500';
    private const string WARNING = 'bg-warning-500/10 text-warning-600';
    private const string DANGER = 'bg-danger-500/10 text-danger-600';

    /** @var array<string, array{classes: string, icon: string, label: string, family: string}> */
    private const array STYLES = [
        'document.uploaded' => ['classes' => self::INFO, 'icon' => 'ti-upload', 'label' => 'Uploaded a document', 'family' => 'documents'],
        'document.signed' => ['classes' => self::SUCCESS, 'icon' => 'ti-signature', 'label' => 'Signed a document', 'family' => 'documents'],
        'document.accessed' => ['classes' => self::NEUTRAL, 'icon' => 'ti-eye', 'label' => 'Opened a document', 'family' => 'documents'],
        'document.version_access_granted' => ['classes' => self::PRIMARY, 'icon' => 'ti-key', 'label' => 'Handed over a key', 'family' => 'documents'],
        'document.shared' => ['classes' => self::PRIMARY, 'icon' => 'ti-key', 'label' => 'Shared a document', 'family' => 'documents'],
        'document.share_revoked' => ['classes' => self::WARNING, 'icon' => 'ti-key-off', 'label' => 'Took a key back', 'family' => 'documents'],
        'document.erased' => ['classes' => self::DANGER, 'icon' => 'ti-trash', 'label' => 'Erased a document', 'family' => 'documents'],
        'document.signing_failed' => ['classes' => self::DANGER, 'icon' => 'ti-alert-octagon', 'label' => 'Signing failed', 'family' => 'documents'],
        'signing_request.created' => ['classes' => self::INFO, 'icon' => 'ti-send', 'label' => 'Sent for signature', 'family' => 'signing'],
        'signing_request.turn_advanced' => ['classes' => self::INFO, 'icon' => 'ti-arrow-right', 'label' => 'The turn moved on', 'family' => 'signing'],
        'signing_request.completed' => ['classes' => self::SUCCESS, 'icon' => 'ti-circle-check', 'label' => 'A request completed', 'family' => 'signing'],
        'signing_request.declined' => ['classes' => self::DANGER, 'icon' => 'ti-ban', 'label' => 'A request was declined', 'family' => 'signing'],
        'signing_request.expired' => ['classes' => self::WARNING, 'icon' => 'ti-alert-triangle', 'label' => 'A request expired', 'family' => 'signing'],
        'signing_request.cancelled' => ['classes' => self::NEUTRAL, 'icon' => 'ti-arrow-back-up', 'label' => 'A request was withdrawn', 'family' => 'signing'],
        'signing_request.access_revoked' => ['classes' => self::WARNING, 'icon' => 'ti-key-off', 'label' => "A signer's key was taken back", 'family' => 'signing'],
        'delivery.served' => ['classes' => self::PRIMARY, 'icon' => 'ti-mail-forward', 'label' => 'Delivered a document', 'family' => 'delivery'],
        'receipt.sealed' => ['classes' => self::PRIMARY, 'icon' => 'ti-receipt', 'label' => 'A receipt was sealed', 'family' => 'receipts'],
        'receipt.seal_failed' => ['classes' => self::DANGER, 'icon' => 'ti-receipt-off', 'label' => 'A receipt could not be sealed', 'family' => 'receipts'],
        'certificate.issued' => ['classes' => self::SUCCESS, 'icon' => 'ti-shield-check', 'label' => 'Certificate issued', 'family' => 'certificates'],
        'certificate.revoked' => ['classes' => self::DANGER, 'icon' => 'ti-shield-x', 'label' => 'Certificate revoked', 'family' => 'certificates'],
        'certificate.held' => ['classes' => self::WARNING, 'icon' => 'ti-shield-pause', 'label' => 'Certificate put on hold', 'family' => 'certificates'],
        'certificate.hold_released' => ['classes' => self::SUCCESS, 'icon' => 'ti-shield-check', 'label' => 'Hold released', 'family' => 'certificates'],
        'certificate.pin_changed' => ['classes' => self::INFO, 'icon' => 'ti-password', 'label' => 'PIN changed', 'family' => 'certificates'],
        'certificate.pin_failed' => ['classes' => self::WARNING, 'icon' => 'ti-lock-question', 'label' => 'Wrong PIN', 'family' => 'certificates'],
        'certificate.pin_locked' => ['classes' => self::DANGER, 'icon' => 'ti-lock', 'label' => 'Certificate locked', 'family' => 'certificates'],
        'certificate.unlocked' => ['classes' => self::SUCCESS, 'icon' => 'ti-lock-open', 'label' => 'Certificate unlocked', 'family' => 'certificates'],
        'certificate.pin_desync' => ['classes' => self::DANGER, 'icon' => 'ti-alert-octagon', 'label' => 'Token rejected a verified PIN', 'family' => 'certificates'],
        'certificate.token_cleanup_failed' => ['classes' => self::WARNING, 'icon' => 'ti-alert-triangle', 'label' => 'Token cleanup failed', 'family' => 'certificates'],
        'certificate.issuance_failed' => ['classes' => self::DANGER, 'icon' => 'ti-alert-octagon', 'label' => 'Issuance failed', 'family' => 'certificates'],
    ];

    /** Families a reader can filter by, in display order. */
    public const array FAMILIES = ['documents' => 'Documents', 'signing' => 'Signing', 'delivery' => 'Delivery', 'receipts' => 'Receipts', 'certificates' => 'Certificates'];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('audit_action_style', self::style(...)),
        ];
    }

    /** @return array{classes: string, icon: string, label: string, family: string} */
    public static function style(string $action): array
    {
        return self::STYLES[$action] ?? ['classes' => self::NEUTRAL, 'icon' => 'ti-point', 'label' => $action, 'family' => 'other'];
    }

    /** @return list<string> every action in a family */
    public static function actionsOf(string $family): array
    {
        return array_keys(array_filter(self::STYLES, static fn (array $s): bool => $s['family'] === $family));
    }
}
