<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Attribute;

use Survos\FieldBundle\Enum\Widget;

/**
 * Declares how a property behaves in search, grid, and filter contexts.
 *
 * Labels are derived from the property name (TitleCase) and resolved through
 * the 'fields' translation domain — set $transKey only to override the key.
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
    public const TRANSLATION_DOMAIN = 'fields';

    public function __construct(
        /**
         * Override the translation key used to resolve the display label.
         * Defaults to the property name; looked up in the 'fields' translation domain.
         * Leave null to use the auto-generated TitleCase fallback.
         */
        public readonly ?string $transKey = null,

        /** Include in full-text search. Maps to DataTables search / Meili searchable fields. */
        public readonly bool $searchable = false,

        /** Allow ordering by this field. Maps to DataTables order / Meili sortable fields. */
        public readonly bool $sortable = false,

        /** Expose a filter control. Determines which $widget is rendered. */
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

        /** Include in the facet panel (Meilisearch sidebar, ColumnControl searchList, UX-Search refinements). */
        public readonly bool $facet = false,

        /** Show this field by default. Hidden fields remain available via column toggle. */
        public readonly bool $visible = true,

        /** Display position (lower = further left). */
        public readonly int $order = 100,

        /** CSS width hint, e.g. '8rem', '120px'. Passed to the grid renderer. */
        public readonly ?string $width = null,

        /**
         * Display format hint for the renderer.
         * Common values: 'date', 'datetime', 'currency', 'percent', 'bytes', 'boolean'.
         */
        public readonly ?string $format = null,

        /**
         * Column group label. Columns sharing the same group are rendered under a shared
         * spanning header row in the grid (e.g. 'Dimensions', 'Engine Info').
         * Ungrouped columns span both header rows (rowspan="2").
         */
        public readonly ?string $group = null,
    ) {}

    /** True when the widget renders as a selectable list (Select, Boolean). */
    public bool $isBrowsable {
        get => $this->widget?->isBrowsable() ?? false;
    }
}
