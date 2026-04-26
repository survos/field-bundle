<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Service;

use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;
use Survos\FieldBundle\Model\FieldDescriptor;

/**
 * Reads all available attribute sources for a class and returns FieldDescriptors.
 *
 * Sources (all optional except #[Field]):
 *   1. #[Field]                              — always read (our attribute)
 *   2. #[With] from symfony/ai-platform     — description, example, enum, min/max
 *   3. #[ApiProperty] from api-platform     — description, example
 *   4. PHP reflection                       — property name, type
 */
final class FieldReader
{
    /** @var array<string, list<FieldDescriptor>> */
    private array $cache = [];

    /**
     * @return list<FieldDescriptor>
     */
    public function getDescriptors(string $class): array
    {
        return $this->cache[$class] ??= $this->buildDescriptors($class);
    }

    public function getDescriptor(string $class, string $propertyName): ?FieldDescriptor
    {
        foreach ($this->getDescriptors($class) as $descriptor) {
            if ($descriptor->name === $propertyName) {
                return $descriptor;
            }
        }
        return null;
    }

    /** @return list<FieldDescriptor> */
    private function buildDescriptors(string $class): array
    {
        $rc = new \ReflectionClass($class);
        $descriptors = [];

        foreach ($rc->getProperties() as $property) {
            $fieldAttr = $this->readField($property);
            if ($fieldAttr === null) {
                continue;
            }

            $type        = $this->resolveType($property);
            $description = null;
            $example     = null;
            $enum        = [];
            $minimum     = null;
            $maximum     = null;

            // #[With] from symfony/ai-platform (optional)
            $with = $this->readWith($property);
            if ($with !== null) {
                $description = $with->description ?? $description;
                $example     = $with->example     ?? $example;
                $enum        = $with->enum        ?? $enum;
                $minimum     = $with->minimum     ?? $minimum;
                $maximum     = $with->maximum     ?? $maximum;
            }

            // #[ApiProperty] from api-platform (optional)
            $apiProp = $this->readApiProperty($property);
            if ($apiProp !== null) {
                $description ??= $this->extractApiPropertyDescription($apiProp);
                $example     ??= $this->extractApiPropertyExample($apiProp);
            }

            // Infer enum values from backed enum type
            if (empty($enum) && $type !== 'string' && enum_exists($type)) {
                $rc2 = new \ReflectionEnum($type);
                if ($rc2->isBacked()) {
                    $enum = array_map(fn ($case) => $case->getBackingValue(), $rc2->getCases());
                }
            }

            $descriptors[] = new FieldDescriptor(
                name:        $property->getName(),
                type:        $type,
                label:       $fieldAttr->label,
                description: $description,
                example:     $example,
                searchable:  $fieldAttr->searchable,
                sortable:    $fieldAttr->sortable,
                filterable:  $fieldAttr->filterable,
                widget:      $fieldAttr->widget,
                facet:       $fieldAttr->facet,
                visible:     $fieldAttr->visible,
                order:       $fieldAttr->order,
                width:       $fieldAttr->width,
                format:      $fieldAttr->format,
                enum:        $enum,
                minimum:     $minimum,
                maximum:     $maximum,
            );
        }

        usort($descriptors, fn (FieldDescriptor $a, FieldDescriptor $b) => $a->order <=> $b->order);

        return $descriptors;
    }

    private function readField(\ReflectionProperty $property): ?Field
    {
        $attrs = $property->getAttributes(Field::class);
        return $attrs ? $attrs[0]->newInstance() : null;
    }

    private function readWith(\ReflectionProperty $property): ?object
    {
        if (!class_exists(\Symfony\AI\Platform\Contract\JsonSchema\Attribute\With::class)) {
            return null;
        }
        $attrs = $property->getAttributes(\Symfony\AI\Platform\Contract\JsonSchema\Attribute\With::class);
        return $attrs ? $attrs[0]->newInstance() : null;
    }

    private function readApiProperty(\ReflectionProperty $property): ?object
    {
        if (!class_exists(\ApiPlatform\Metadata\ApiProperty::class)) {
            return null;
        }
        $attrs = $property->getAttributes(\ApiPlatform\Metadata\ApiProperty::class);
        return $attrs ? $attrs[0]->newInstance() : null;
    }

    private function extractApiPropertyDescription(object $apiProperty): ?string
    {
        return $apiProperty->getDescription() ?? null;
    }

    private function extractApiPropertyExample(object $apiProperty): mixed
    {
        return $apiProperty->getExample() ?? null;
    }

    private function resolveType(\ReflectionProperty $property): string
    {
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        return 'string';
    }
}
