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
 *   2. Symfony validation constraints        — #[Url], #[Email], #[NotBlank], #[Length], #[Range], #[Regex]
 *   3. #[With] from symfony/ai-platform     — description, example, enum, min/max
 *   4. #[ApiProperty] from api-platform     — description, example
 *   5. PHP reflection                       — property name, type, backed enum cases
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
            $maxLength   = null;
            $pattern     = null;
            $required    = false;
            $isUrl       = false;
            $isEmail     = false;

            // Symfony validation constraints (optional — only if symfony/validator is present)
            $this->readConstraints(
                $property, $minimum, $maximum, $maxLength, $pattern, $required, $isUrl, $isEmail
            );

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
                transKey:    $fieldAttr->transKey,
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
                maxLength:   $maxLength,
                pattern:     $pattern,
                required:    $required,
                isUrl:       $isUrl,
                isEmail:     $isEmail,
            );
        }

        usort($descriptors, fn (FieldDescriptor $a, FieldDescriptor $b) => $a->order <=> $b->order);

        return $descriptors;
    }

    private function readConstraints(
        \ReflectionProperty $property,
        int|float|null &$minimum,
        int|float|null &$maximum,
        ?int &$maxLength,
        ?string &$pattern,
        bool &$required,
        bool &$isUrl,
        bool &$isEmail,
    ): void {
        if (!class_exists(\Symfony\Component\Validator\Constraints\NotBlank::class)) {
            return;
        }

        foreach ($property->getAttributes() as $attr) {
            $name = $attr->getName();
            switch ($name) {
                case \Symfony\Component\Validator\Constraints\NotBlank::class:
                    $required = true;
                    break;

                case \Symfony\Component\Validator\Constraints\Url::class:
                    $isUrl = true;
                    break;

                case \Symfony\Component\Validator\Constraints\Email::class:
                    $isEmail = true;
                    break;

                case \Symfony\Component\Validator\Constraints\Length::class:
                    $args = $attr->getArguments();
                    $maxLength ??= $args['max'] ?? $args[1] ?? null;
                    break;

                case \Symfony\Component\Validator\Constraints\Range::class:
                    $args = $attr->getArguments();
                    $minimum ??= $args['min'] ?? $args[0] ?? null;
                    $maximum ??= $args['max'] ?? $args[1] ?? null;
                    break;

                case \Symfony\Component\Validator\Constraints\Positive::class:
                case \Symfony\Component\Validator\Constraints\PositiveOrZero::class:
                    $minimum ??= ($name === \Symfony\Component\Validator\Constraints\PositiveOrZero::class) ? 0 : 1;
                    break;

                case \Symfony\Component\Validator\Constraints\Regex::class:
                    $args = $attr->getArguments();
                    $pattern ??= $args['pattern'] ?? $args[0] ?? null;
                    break;
            }
        }
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
