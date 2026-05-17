<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Hydrates a BaseItemDto subclass from a flat array (e.g. normalized JSONL row).
 *
 * Uses the pre-compiled MappingRegistry (built by DtoMappingCompilerPass) so
 * there is zero per-request reflection. PropertyAccessor handles PHP 8.4
 * property hooks and type coercion automatically.
 */
final class DtoHydrator
{
    public function __construct(
        private readonly MappingRegistry $registry,
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {}

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, mixed> $row
     * @return T
     */
    public function hydrate(string $class, array $row): object
    {
        $dto = new $class();

        foreach ($row as $sourceKey => $value) {
            $prop = $this->registry->resolve($class, $sourceKey) ?? $this->camelCase($sourceKey);

            if ($this->propertyAccessor->isWritable($dto, $prop)) {
                $this->propertyAccessor->setValue($dto, $prop, $value);
            } elseif (property_exists($dto, 'unmapped')) {
                $dto->unmapped[$sourceKey] = $value;
            }
        }

        return $dto;
    }

    private function camelCase(string $key): string
    {
        return lcfirst(str_replace('_', '', ucwords($key, '_')));
    }
}
