<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

/**
 * Maps a DTO property from a source record and carries field metadata.
 *
 * The source-mapping options are intentionally lightweight so import-style
 * mappers can consume them without requiring a full import pipeline package.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::IS_REPEATABLE)]
class Map
{
    /**
     * @param string|list<string>|null $source One or more source keys to read from the record, in
     *                            priority order — the first key present with a non-null value wins
     *                            (the canonical "alias list", e.g. ['title', 'dcterms:title', 'titulo']).
     *                            Falls back to the property name when none match.
     * @param string|null $regex Regex applied to record keys; first match wins.
     * @param string|null $if Conditional: 'isset' = skip if resolved value is null.
     * @param string|null $delim Delimiter for splitting a string into an array property.
     * @param int $priority Mapper priority when multiple sources exist; higher wins.
     * @param string[] $when Only apply when context['dataset'] is in this list.
     * @param string[] $except Skip when context['dataset'] is in this list.
     * @param bool $facet Meilisearch filterableAttributes.
     * @param bool $sortable Meilisearch sortableAttributes.
     * @param bool $searchable Meilisearch searchableAttributes.
     * @param bool $translatable Mark field for translation pipelines.
     */
    public function __construct(
        public readonly string|array|null $source = null,
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

    /**
     * The source keys to try, in priority order. A bare string becomes a one-element list, so
     * callers can always iterate uniformly.
     *
     * @return list<string>
     */
    public function sources(): array
    {
        if ($this->source === null) {
            return [];
        }

        return is_array($this->source) ? array_values($this->source) : [$this->source];
    }
}
