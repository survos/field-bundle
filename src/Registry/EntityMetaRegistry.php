<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Registry;

use Survos\FieldBundle\Model\EntityMetaDescriptor;

/**
 * Runtime service holding all EntityMetaDescriptors compiled by EntityMetaPass.
 * Injected with the compiled descriptor list — no runtime scanning.
 */
final class EntityMetaRegistry
{
    /** @param EntityMetaDescriptor[] $descriptors */
    public function __construct(
        private readonly array $descriptors = [],
    ) {}

    /** @return EntityMetaDescriptor[] */
    public function getAll(): array
    {
        return $this->descriptors;
    }

    /** @return EntityMetaDescriptor[] */
    public function getBrowsable(): array
    {
        return array_values(array_filter($this->descriptors, fn ($d) => $d->adminBrowsable));
    }

    public function get(string $class): ?EntityMetaDescriptor
    {
        foreach ($this->descriptors as $d) {
            if ($d->class === $class) {
                return $d;
            }
        }
        return null;
    }

    /** @return string[] */
    public function getGroups(): array
    {
        return array_values(array_unique(array_map(fn ($d) => $d->group, $this->descriptors)));
    }

    /** @return EntityMetaDescriptor[] */
    public function getByGroup(string $group): array
    {
        return array_values(array_filter($this->descriptors, fn ($d) => $d->group === $group));
    }
}
