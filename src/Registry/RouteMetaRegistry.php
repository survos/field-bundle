<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Registry;

use Survos\FieldBundle\Enum\Audience;
use Survos\FieldBundle\Enum\Purpose;
use Survos\FieldBundle\Model\RouteMetaDescriptor;

/**
 * Runtime registry of every controller route carrying #[RouteMeta].
 * Populated at compile time by RouteMetaPass; no runtime scanning.
 */
final class RouteMetaRegistry
{
    /** @var array<string, RouteMetaDescriptor> */
    private readonly array $byName;

    /** @var array<class-string, list<RouteMetaDescriptor>> */
    private readonly array $byEntity;

    /** @param list<RouteMetaDescriptor> $descriptors */
    public function __construct(
        private readonly array $descriptors = [],
    ) {
        $byName   = [];
        $byEntity = [];

        foreach ($descriptors as $d) {
            $byName[$d->name] = $d;
            if ($d->entity !== null) {
                $byEntity[$d->entity][] = $d;
            }
        }

        $this->byName   = $byName;
        $this->byEntity = $byEntity;
    }

    /** @return list<RouteMetaDescriptor> */
    public function all(): array
    {
        return $this->descriptors;
    }

    public function get(string $routeName): ?RouteMetaDescriptor
    {
        return $this->byName[$routeName] ?? null;
    }

    /** @return list<RouteMetaDescriptor> */
    public function forEntity(string $fqcn): array
    {
        return $this->byEntity[$fqcn] ?? [];
    }

    public function forEntityPurpose(string $fqcn, Purpose $purpose): ?RouteMetaDescriptor
    {
        foreach ($this->forEntity($fqcn) as $d) {
            if ($d->purpose === $purpose) {
                return $d;
            }
        }
        return null;
    }

    /** @return list<RouteMetaDescriptor> */
    public function byPurpose(Purpose $purpose): array
    {
        return array_values(array_filter($this->descriptors, static fn ($d) => $d->purpose === $purpose));
    }

    /** @return list<RouteMetaDescriptor> */
    public function byAudience(Audience $audience): array
    {
        return array_values(array_filter($this->descriptors, static fn ($d) => $d->audience === $audience));
    }

    /** @return list<RouteMetaDescriptor> */
    public function byTag(string $tag): array
    {
        return array_values(array_filter(
            $this->descriptors,
            static fn ($d) => \in_array($tag, $d->tags, true),
        ));
    }

    /** @return list<class-string> */
    public function knownEntities(): array
    {
        return array_keys($this->byEntity);
    }
}
