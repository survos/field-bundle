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
    /** @var array<class-string, EntityMetaDescriptor> */
    private readonly array $byClass;

    /** @var array<string, EntityMetaDescriptor[]> */
    private readonly array $byGroup;

    /** @param EntityMetaDescriptor[] $descriptors */
    public function __construct(
        private readonly array $descriptors = [],
    ) {
        $byClass = [];
        $byGroup = [];

        foreach ($descriptors as $descriptor) {
            $byClass[$descriptor->class] = $descriptor;
            $byGroup[$descriptor->group][] = $descriptor;
        }

        $this->byClass = $byClass;
        $this->byGroup = $byGroup;
    }

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
        return $this->byClass[$class] ?? null;
    }

    /** @return string[] */
    public function getGroups(): array
    {
        return array_keys($this->byGroup);
    }

    /** @return EntityMetaDescriptor[] */
    public function getByGroup(string $group): array
    {
        return $this->byGroup[$group] ?? [];
    }
}
