<?php

declare(strict_types=1);

namespace App\Core\Twig;

use App\Core\Navigation\SidebarMenuProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class NavigationExtension extends AbstractExtension
{
    public function __construct(private readonly SidebarMenuProvider $sidebarMenu) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sidebar_menu', $this->sidebarMenu->getItems(...)),
        ];
    }
}
