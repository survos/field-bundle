<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

use Survos\FieldBundle\Enum\Widget;

/**
 * Declares how a property behaves in search, grid, and filter contexts.
 *
 * This attribute is intentionally orthogonal to:
 *   - #[ApiProperty]  → OpenAPI / API documentation  (api-platform)
 *   - #[With]         → JSON Schema constraints for LLMs (symfony/ai)
 *   - #[ORM\Column]   → database storage              (doctrine)
 *
 * Consumed by:
 *   - survos/grid-bundle      → DataTables column config
 *   - survos/api-grid-bundle  → ColumnControl content types
 *   - survos/meili-bundle     → Meilisearch searchable/filterable/sortable/facet settings
 *   - survos/inspection-bundle → unified FieldDescriptor
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class Field
{
    public function __construct(
        /** Human-readable column/field label. Defaults to the property name if null. */
        public readonly ?string $label = null,

        /** Include in full-text search. Maps to DataTables search input / Meili searchable fields. */
        public readonly bool $searchable = false,

        /** Allow ordering by this field. Maps to DataTables order / Meili sortable fields. */
        public readonly bool $sortable = false,

        /** Expose a filter control for this field. Determines which $widget is rendered. */
        public readonly bool $filterable = false,

        /**
         * The filter UI widget. Inferred from the property type when null:
         *   bool      → Widget::Boolean
         *   int/float → Widget::Range
         *   \DateTimeInterface → Widget::Date
         *   backed enum → Widget::Select
         *   string    → Widget::Text
         */
        public readonly ?Widget $widget = null,

        /** Include in the facet panel (Meilisearch sidebar, SearchPanes, UX-Search refinements). */
        public readonly bool $facet = false,

        /** Show this field by default. Hidden fields are still available via column toggle. */
        public readonly bool $visible = true,

        /** Display position (lower = further left). */
        public readonly int $order = 100,

        /** CSS width hint, e.g. '8rem', '120px'. Passed to the grid renderer. */
        public readonly ?string $width = null,

        /**
         * Display format hint for the renderer.
         * Common values: 'date', 'datetime', 'currency', 'percent', 'bytes', 'boolean'.
         * Renderers may define additional format tokens.
         */
        public readonly ?string $format = null,
    ) {}
}
