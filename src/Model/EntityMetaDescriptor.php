<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Model;

/**
 * Compiled metadata for one entity class, produced by EntityMetaPass.
 * Serialisable — stored as a container parameter and injected into EntityMetaRegistry.
 */
final class EntityMetaDescriptor
{
    public function __construct(
        public readonly string  $class,
        public readonly string  $label,
        public readonly string  $group,
        public readonly int     $order,
        public readonly ?string $icon          = null,
        public readonly ?string $iconClass     = null,
        public readonly ?string $description   = null,
        public readonly bool    $adminBrowsable = true,
        public readonly bool    $hasApiResource = false,
        public readonly bool    $hasMeiliIndex  = false,
        /** snake_case routing code, e.g. "app_song" / "pixie_foo". Computed at compile time. */
        public readonly string  $code           = '',
        /** Twig globals key, e.g. "APP_ENTITY_SONG". Computed at compile time. */
        public readonly string  $globalKey      = '',
        /** @var array<string, EntityViewDescriptor> */
        public readonly array   $views          = [],
        public readonly string  $defaultView    = 'table',
    ) {}

    public function getShortName(): string
    {
        return substr(strrchr($this->class, '\\') ?: $this->class, 1);
    }

    public function view(?string $code = null): ?EntityViewDescriptor
    {
        $code ??= $this->defaultView;

        return $this->views[$code] ?? null;
    }

    /** @return EntityViewDescriptor[] */
    public function viewChoices(): array
    {
        return array_values($this->views);
    }
}
