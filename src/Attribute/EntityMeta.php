<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

/**
 * Class-level metadata for admin UI, dashboards, and menu auto-registration.
 *
 * Discovered at compile time by EntityMetaPass, which scans all Doctrine
 * entity directories (app + bundles) for this attribute.
 *
 * Example:
 *   #[EntityMeta(icon: 'mdi:building', group: 'Content', order: 10)]
 *   class Tenant { ... }
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class EntityMeta
{
    public function __construct(
        /** UX icon name, e.g. 'mdi:building' or 'tabler:user' */
        public readonly ?string $icon = null,

        /** CSS class applied to the icon, e.g. 'text-primary' */
        public readonly ?string $iconClass = null,

        /** Display position within the group (lower = first). */
        public readonly int $order = 100,

        /** Free-form group name — becomes a submenu/section header. */
        public readonly string $group = 'General',

        /** Human-readable label; defaults to short class name. */
        public readonly ?string $label = null,

        /** One-line description shown on the admin dashboard. */
        public readonly ?string $description = null,

        /** Whether to include this entity in the admin navbar and dashboard. */
        public readonly bool $adminBrowsable = true,
    ) {}
}
