<?php

declare(strict_types=1);

namespace App\Core\Navigation;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Single source of truth for the app sidebar. Add items here; per-role menus
 * come from the $role gate (e.g. ROLE_ADMIN entries appear only for admins).
 * Icons are Lucide-style inline path markup for a 24×24 stroke svg.
 */
final readonly class SidebarMenuProvider
{
    public function __construct(private AuthorizationCheckerInterface $auth) {}

    /**
     * @return list<MenuItem>
     */
    public function getItems(): array
    {
        $items = [
            new MenuItem(
                label: 'Dashboard',
                route: 'app_dashboard',
                icon: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            ),
            new MenuItem(
                label: 'Documents',
                route: null, // TODO: app_documents (Document module)
                icon: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
            ),
            new MenuItem(
                label: 'Signing requests',
                route: null, // TODO: app_signing_requests (Signing module)
                icon: '<path d="M4 13h4l2 3h4l2-3h4"/><path d="M5 13V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v7"/>',
                badgeKey: 'signing_requests',
            ),
            new MenuItem(
                label: 'Certificate',
                route: null, // TODO: app_certificate (Certificate module)
                icon: '<path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z"/><path d="M9 12l2 2 4-4"/>',
            ),
            new MenuItem(
                label: 'Audit log',
                route: null, // TODO: app_audit_log (AuditLog module)
                icon: '<path d="M8 6h11M8 12h11M8 18h11"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
            ),
            new MenuItem(
                label: 'Statistics',
                route: null, // TODO: app_admin_statistics (privacy-preserving admin stats, last phase)
                icon: '<path d="M3 3v18h18"/><path d="M7 15v3M12 10v8M17 6v12"/>',
                role: 'ROLE_ADMIN',
            ),
        ];

        return array_values(array_filter(
            $items,
            fn (MenuItem $item): bool => $item->role === null || $this->auth->isGranted($item->role),
        ));
    }
}
