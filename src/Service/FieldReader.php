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

        // MeiliIndex-derived field sets (optional dependency; all empty if bundle absent)
        $meiliIndex       = $this->readMeiliIndex($rc);
        $meiliSearchable  = $meiliIndex ? $this->meiliFieldNames($meiliIndex->searchable)  : [];
        $meiliFilterable  = $meiliIndex ? $this->meiliFieldNames($meiliIndex->filterable)  : [];
        $meiliSortable    = $meiliIndex ? $this->meiliFieldNames($meiliIndex->sortable)    : [];
        $meiliAll         = array_unique(array_merge($meiliSearchable, $meiliFilterable, $meiliSortable));

        // ApiPlatform #[ApiFilter] — the server-side ground truth for sort/search/filter capability
        $apiFilters    = $this->readApiFilters($rc);
        $apiSortable   = $apiFilters['sortable'];
        $apiSearchable = $apiFilters['searchable'];
        $apiFilterable = $apiFilters['filterable'];
        $apiAll        = array_unique(array_merge($apiSortable, $apiSearchable, $apiFilterable));

        // Gather properties and getter methods that carry #[Field], preserving declaration order.
        // Properties from used traits appear first (PHP reflection contract).
        $members = [
            ...$rc->getProperties(),
            ...array_filter($rc->getMethods(\ReflectionMethod::IS_PUBLIC), fn (\ReflectionMethod $m) => !$m->isStatic() && $m->getNumberOfRequiredParameters() === 0 && str_starts_with($m->getName(), 'get')),
        ];

        foreach ($members as $member) {
            // Derive name first so we can check MeiliIndex membership.
            $name = $member instanceof \ReflectionProperty
                ? $member->getName()
                : lcfirst(substr($member->getName(), 3)); // getAccCount → accCount

            $fieldAttr = $member instanceof \ReflectionProperty
                ? $this->readField($member)
                : $this->readFieldFromMethod($member);

            if ($fieldAttr === null) {
                if (!in_array($name, $meiliAll, true) && !in_array($name, $apiAll, true)) {
                    continue;
                }
                // Synthesize a Field from MeiliIndex and/or ApiFilter metadata.
                $fieldAttr = new Field(
                    searchable: in_array($name, $meiliSearchable, true) || in_array($name, $apiSearchable, true),
                    sortable:   in_array($name, $meiliSortable,   true) || in_array($name, $apiSortable,   true),
                    filterable: in_array($name, $meiliFilterable,  true) || in_array($name, $apiFilterable,  true),
                );
            }

            $type        = $member instanceof \ReflectionProperty
                ? $this->resolveType($member)
                : $this->resolveMethodReturnType($member);
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

            // Symfony validation constraints — only applicable to properties
            if ($member instanceof \ReflectionProperty) {
                $this->readConstraints(
                    $member, $minimum, $maximum, $maxLength, $pattern, $required, $isUrl, $isEmail
                );
            }

            // #[With] from symfony/ai-platform (optional)
            $with = $member instanceof \ReflectionProperty ? $this->readWith($member) : null;
            if ($with !== null) {
                $description = $with->description ?? $description;
                $example     = $with->example     ?? $example;
                $enum        = $with->enum        ?? $enum;
                $minimum     = $with->minimum     ?? $minimum;
                $maximum     = $with->maximum     ?? $maximum;
            }

            // #[ApiProperty] from api-platform (optional)
            $apiProp = $member instanceof \ReflectionProperty ? $this->readApiProperty($member) : null;
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
                name:        $name,
                type:        $type,
                transKey:    $fieldAttr->transKey,
                description: $description,
                example:     $example,
                searchable:  $fieldAttr->searchable || in_array($name, $apiSearchable, true),
                sortable:    $fieldAttr->sortable   || in_array($name, $apiSortable,   true),
                filterable:  $fieldAttr->filterable || in_array($name, $apiFilterable,  true),
                widget:      $fieldAttr->widget,
                facet:       $fieldAttr->facet,
                visible:     $fieldAttr->visible,
                order:       $fieldAttr->order,
                width:       $fieldAttr->width,
                group:       $fieldAttr->group,
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

        // Stable sort: primary key is $order, tiebreaker is declaration index so
        // "all default (100)" preserves the original property/method order.
        $indexed = array_map(null, $descriptors, array_keys($descriptors));
        usort($indexed, fn ($a, $b) => $a[0]->order <=> $b[0]->order ?: $a[1] <=> $b[1]);

        return array_column($indexed, 0);
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

    private function readFieldFromMethod(\ReflectionMethod $method): ?Field
    {
        $attrs = $method->getAttributes(Field::class);
        return $attrs ? $attrs[0]->newInstance() : null;
    }

    private function resolveMethodReturnType(\ReflectionMethod $method): string
    {
        $type = $method->getReturnType();
        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }
        return 'string';
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

    /** @return array{sortable: string[], searchable: string[], filterable: string[]} */
    private function readApiFilters(\ReflectionClass $rc): array
    {
        $empty = ['sortable' => [], 'searchable' => [], 'filterable' => []];
        if (!class_exists(\ApiPlatform\Metadata\ApiFilter::class)) {
            return $empty;
        }

        $sortable   = [];
        $searchable = [];
        $filterable = [];

        foreach ($rc->getAttributes(\ApiPlatform\Metadata\ApiFilter::class) as $attr) {
            $filter      = $attr->newInstance();
            $filterClass = $filter->filterClass ?? '';
            $properties  = $filter->properties  ?? [];

            $propNames = [];
            foreach ($properties as $key => $value) {
                $propNames[] = is_int($key) ? $value : $key;
            }

            match (true) {
                is_a($filterClass, 'ApiPlatform\Doctrine\Orm\Filter\OrderFilter',  true) => $sortable   = array_merge($sortable,   $propNames),
                is_a($filterClass, 'ApiPlatform\Doctrine\Orm\Filter\SearchFilter', true) => $searchable = array_merge($searchable, $propNames),
                is_a($filterClass, 'ApiPlatform\Doctrine\Orm\Filter\ExactFilter',  true) => $filterable = array_merge($filterable, $propNames),
                is_a($filterClass, 'ApiPlatform\Doctrine\Orm\Filter\RangeFilter',  true) => $filterable = array_merge($filterable, $propNames),
                default => null,
            };
        }

        return [
            'sortable'   => array_unique($sortable),
            'searchable' => array_unique($searchable),
            'filterable' => array_unique($filterable),
        ];
    }

    private function readMeiliIndex(\ReflectionClass $rc): ?object
    {
        if (!class_exists(\Survos\MeiliBundle\Metadata\MeiliIndex::class)) {
            return null;
        }
        $attrs = $rc->getAttributes(\Survos\MeiliBundle\Metadata\MeiliIndex::class);
        return $attrs ? $attrs[0]->newInstance() : null;
    }

    /** @return string[] */
    private function meiliFieldNames(mixed $value): array
    {
        if (empty($value)) {
            return [];
        }
        if (class_exists(\Survos\MeiliBundle\Metadata\Fields::class) && $value instanceof \Survos\MeiliBundle\Metadata\Fields) {
            return $value->fields;
        }
        return array_values(array_filter((array) $value, 'is_string'));
    }
}
