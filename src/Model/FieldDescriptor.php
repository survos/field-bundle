<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Model;

use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;

/**
 * Unified property metadata assembled by FieldReader.
 *
 * Labels are translation keys resolved in the 'fields' domain.
 * Call getLabel() for the key, then translate: getLabel()|trans({}, getTranslationDomain())
 * The auto-generated fallback (TitleCase of property name) is used when no translation exists.
 */
final class FieldDescriptor
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $type         = 'string',
        /** Explicit translation key override; null = derived from $name. */
        public readonly ?string $transKey     = null,
        public readonly ?string $description  = null,
        public readonly mixed   $example      = null,
        public readonly bool    $searchable   = false,
        public readonly bool    $sortable     = false,
        public readonly bool    $filterable   = false,
        public readonly ?Widget $widget       = null,
        public readonly bool    $facet        = false,
        public readonly bool    $visible      = true,
        public readonly int     $order        = 100,
        public readonly ?string $width        = null,
        public readonly ?string $group        = null,
        public readonly ?string $format       = null,
        /** @var list<scalar|null> */
        public readonly array   $enum         = [],
        public readonly int|float|null $minimum  = null,
        public readonly int|float|null $maximum  = null,
        public readonly ?int           $maxLength = null,
        public readonly ?string        $pattern   = null,
        public readonly bool           $required  = false,
        public readonly bool           $isUrl     = false,
        public readonly bool           $isEmail   = false,
    ) {}

    /**
     * Translation key for this field's label.
     * Pass to Twig: {{ descriptor.translationKey | trans({}, descriptor.translationDomain) }}
     */
    public function getTranslationKey(): string
    {
        return $this->transKey ?? $this->name;
    }

    public function getTranslationDomain(): string
    {
        return Field::TRANSLATION_DOMAIN;
    }

    /**
     * Human-readable fallback label when no translation is found.
     * TitleCase of the property name, e.g. 'accountType' → 'Account Type'.
     */
    public function getFallbackLabel(): string
    {
        $name = $this->transKey ?? $this->name;
        // camelCase → words, then titlecase
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?? $name;
        return ucwords(str_replace('_', ' ', $spaced));
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
            $this->type === 'bool'                          => Widget::Boolean,
            in_array($this->type, ['int', 'float'], true)  => Widget::Range,
            str_contains($this->type, 'DateTime')           => Widget::Date,
            count($this->enum) > 0                          => Widget::Select,
            default                                         => Widget::Text,
        };
    }

    /** Input type hint for form rendering (html input type). */
    public function inputType(): string
    {
        return match (true) {
            $this->isEmail                                 => 'email',
            $this->isUrl                                   => 'url',
            in_array($this->type, ['int', 'float'], true) => 'number',
            str_contains($this->type, 'DateTime')          => 'datetime-local',
            default                                        => 'text',
        };
    }
}
