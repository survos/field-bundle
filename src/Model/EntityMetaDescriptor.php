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
    ) {}

    public function getShortName(): string
    {
        return substr(strrchr($this->class, '\\') ?: $this->class, 1);
    }
}
