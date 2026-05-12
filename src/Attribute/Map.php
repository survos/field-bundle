<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

/**
 * Maps a DTO property from a source record and carries field metadata.
 *
 * The source-mapping options are intentionally lightweight so import-style
 * mappers can consume them without requiring a full import pipeline package.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Map
{
    /**
     * @param string|null $source Explicit source key in the record array.
     *                            Falls back to the property name when null.
     * @param string|null $regex Regex applied to record keys; first match wins.
     * @param string|null $if Conditional: 'isset' = skip if resolved value is null.
     * @param string|null $delim Delimiter for splitting a string into an array property.
     * @param int $priority Mapper priority when multiple sources exist; higher wins.
     * @param string[] $when Only apply when context['pixie'] is in this list.
     * @param string[] $except Skip when context['pixie'] is in this list.
     * @param bool $facet Meilisearch filterableAttributes.
     * @param bool $sortable Meilisearch sortableAttributes.
     * @param bool $searchable Meilisearch searchableAttributes.
     * @param bool $translatable Mark field for translation pipelines.
     */
    public function __construct(
        public readonly ?string $source = null,
        public readonly ?string $regex = null,
        public readonly ?string $if = null,
        public readonly ?string $delim = null,
        public readonly int $priority = 10,
        public readonly array $when = [],
        public readonly array $except = [],
        public readonly bool $facet = false,
        public readonly bool $sortable = false,
        public readonly bool $searchable = false,
        public readonly bool $translatable = false,
    ) {
    }
}
