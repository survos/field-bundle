<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Enum;

/**
 * The UI control used to filter a field.
 *
 * Maps to:
 *   - DataTables ColumnControl: Text→'search', Select→'searchList', Range→range controls, Boolean→'searchList'
 *   - Meilisearch facets:       Text→searchable, Select→RefinementList, Range→RangeSlider, Boolean→Toggle, Date→NumericMenu
 *   - UX-Search widgets:        one-to-one with Algolia InstantSearch widget names
 */
enum Widget: string
{
    case Text    = 'text';    // free-text search input
    case Select  = 'select';  // dropdown of distinct values (enum, relation)
    case Range   = 'range';   // numeric range with min/max (< >)
    case Date    = 'date';    // date / datetime range picker
    case Boolean = 'boolean'; // true / false toggle

    /**
     * Whether this widget renders as a list of selectable values
     * (searchList in ColumnControl, RefinementList/Toggle in Meilisearch).
     * Range and Date are continuous controls, not lists.
     */
    public function isBrowsable(): bool
    {
        return match ($this) {
            self::Select, self::Boolean => true,
            default                     => false,
        };
    }
}
