<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Menu;

use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;

final class FieldMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string
    {
        return 'Fields';
    }

    protected function getGroupIcon(): ?string
    {
        return 'code';
    }

    protected function getResourceClasses(): array
    {
        return [];
    }

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $submenu = $this->addSubmenu($event->getMenu(), $this->getLabel(), $this->getGroupIcon());
        $this->add($submenu, 'survos_entity_constants', [], 'Entity Constants', icon: 'code');
        $this->add($submenu, 'survos_routes_sitemap', [], 'Route Sitemap', icon: 'map');
    }
}
