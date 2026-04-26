<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;

final class FieldTest extends TestCase
{
    public function testDefaults(): void
    {
        $field = new Field();

        self::assertNull($field->label);
        self::assertFalse($field->searchable);
        self::assertFalse($field->sortable);
        self::assertFalse($field->filterable);
        self::assertNull($field->widget);
        self::assertFalse($field->facet);
        self::assertTrue($field->visible);
        self::assertSame(100, $field->order);
        self::assertNull($field->width);
        self::assertNull($field->format);
    }

    public function testExplicitValues(): void
    {
        $field = new Field(
            label:      'Status',
            searchable: true,
            sortable:   true,
            filterable: true,
            widget:     Widget::Select,
            facet:      true,
            visible:    false,
            order:      10,
            width:      '8rem',
            format:     'status',
        );

        self::assertSame('Status', $field->label);
        self::assertTrue($field->searchable);
        self::assertTrue($field->sortable);
        self::assertTrue($field->filterable);
        self::assertSame(Widget::Select, $field->widget);
        self::assertTrue($field->facet);
        self::assertFalse($field->visible);
        self::assertSame(10, $field->order);
        self::assertSame('8rem', $field->width);
        self::assertSame('status', $field->format);
    }

    public function testAttributeIsReadableViaReflection(): void
    {
        $class = new class {
            #[Field(label: 'My Label', searchable: true, order: 5)]
            public string $title = '';
        };

        $property = new \ReflectionProperty($class, 'title');
        $attrs = $property->getAttributes(Field::class);

        self::assertCount(1, $attrs);

        $field = $attrs[0]->newInstance();
        self::assertSame('My Label', $field->label);
        self::assertTrue($field->searchable);
        self::assertSame(5, $field->order);
    }
}
