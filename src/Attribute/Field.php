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
         * Column group label. Columns sharing the same group are rendered under a shared
         * spanning header row in the grid (e.g. 'Dimensions', 'Engine Info').
         * Ungrouped columns span both header rows (rowspan="2").
         */
        public readonly ?string $group = null,

        /**
         * Display format hint for the renderer.
         * Common values: 'date', 'datetime', 'currency', 'percent', 'bytes', 'boolean'.
         */
        public readonly ?string $format = null,

        /**
         * This field's value(s) (scalar or array) might resolve against an external authority
         * source (Wikidata, OpenStreetMap/Nominatim, GeoNames, ...) — a first-class-callable
         * reference to the static method that performs the lookup, e.g.
         * `authority: FpusTagAuthorityResolver::resolvePoi(...)` (PHP 8.5's "closures in constant
         * expressions" — see https://wiki.php.net/rfc/closures_in_const_expr — is what makes a
         * callable usable here at all; on PHP < 8.5 this argument position simply can't be filled
         * with anything but null). A generic listener can reflect a row's fields, find one flagged
         * this way, and call `($field->authority)($value)` — no separate string-to-service lookup
         * table needed, the attribute IS the resolution strategy.
         *
         * DO NOT set this on a field declared in a shared library (survos/data-contracts and
         * friends): every existing consumer of this attribute (FolioSchemaSnapshotter,
         * survos/grid-bundle, survos/meili-bundle) unconditionally calls
         * `getAttributes(Field::class)[0]->newInstance()` on EVERY #[Field]-attributed property of
         * every DTO a folio uses — not only when the authority feature is wanted. Since attribute
         * arguments are lazily-evaluated constant expressions (resolved on newInstance(), not on
         * autoload), referencing an app-specific resolver class here means any app lacking that
         * class hits a fatal "class not found" the next time normal schema-building walks the
         * property — immediately, for every consumer, not just ones that asked for this feature.
         * Safe usage: only on a field an app itself declares (or redeclares via an app-owned DTO
         * subclass that shadows a shared property specifically to attach this).
         */
        public readonly ?\Closure $authority = null,
    ) {}

    /** True when the widget renders as a selectable list (Select, Boolean). */
    public bool $isBrowsable {
        get => $this->widget?->isBrowsable() ?? false;
    }
}
