<?php

declare(strict_types=1);

namespace App\Core\Navigation;

/**
 * One sidebar entry. $role gates visibility (null = any authenticated user).
 * $route null = module not built yet: rendered dimmed with a "coming soon" hint.
 * $badgeKey looks up a live count in the badges map passed at render time.
 */
final readonly class MenuItem
{
    public function __construct(
        public string $label,
        public ?string $route,
        public string $icon,
        public ?string $role = null,
        public ?string $badgeKey = null,
    ) {}
}
