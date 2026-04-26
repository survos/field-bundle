<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Model;

use Survos\FieldBundle\Enum\Widget;

/**
 * Unified property metadata assembled by FieldReader from all available sources:
 *   - #[Field]       → searchable, sortable, filterable, widget, facet, visible, order, width, format
 *   - #[With]        → description, example, enum, min, max, pattern  (symfony/ai, optional)
 *   - #[ApiProperty] → description, example                           (api-platform, optional)
 *   - PHP reflection → name, type                                     (always available)
 */
final class FieldDescriptor
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $type        = 'string',
        public readonly ?string $label       = null,
        public readonly ?string $description = null,
        public readonly mixed   $example     = null,
        public readonly bool    $searchable  = false,
        public readonly bool    $sortable    = false,
        public readonly bool    $filterable  = false,
        public readonly ?Widget $widget      = null,
        public readonly bool    $facet       = false,
        public readonly bool    $visible     = true,
        public readonly int     $order       = 100,
        public readonly ?string $width       = null,
        public readonly ?string $format      = null,
        /** @var list<scalar|null> Allowed values (from #[With(enum:)] or backed enum cases) */
        public readonly array   $enum        = [],
        public readonly int|float|null $minimum = null,
        public readonly int|float|null $maximum = null,
    ) {}

    public function getLabel(): string
    {
        return $this->label ?? ucwords(str_replace('_', ' ', $this->name));
    }

    public function resolvedWidget(): ?Widget
    {
        if ($this->widget !== null) {
            return $this->widget;
        }

        if (!$this->filterable) {
            return null;
        }

        return match (true) {
            $this->type === 'bool'                              => Widget::Boolean,
            in_array($this->type, ['int', 'float'], true)      => Widget::Range,
            str_contains($this->type, 'DateTime')               => Widget::Date,
            count($this->enum) > 0                              => Widget::Select,
            default                                             => Widget::Text,
        };
    }
}
