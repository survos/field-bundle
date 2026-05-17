<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

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

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $submenu = $this->addSubmenu($event->getMenu(), $this->getLabel(), $this->getGroupIcon());
        $this->add($submenu, 'survos_entity_constants', [], 'Entity Constants', icon: 'code');
    }
}
