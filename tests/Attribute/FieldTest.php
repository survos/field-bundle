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

        self::assertNull($field->transKey);
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
            transKey:   'tenant.status',
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

        self::assertSame('tenant.status', $field->transKey);
        self::assertTrue($field->searchable);
        self::assertTrue($field->filterable);
        self::assertSame(Widget::Select, $field->widget);
        self::assertFalse($field->visible);
        self::assertSame(10, $field->order);
    }

    public function testAttributeIsReadableViaReflection(): void
    {
        $class = new class {
            #[Field(transKey: 'my_label', searchable: true, order: 5)]
            public string $title = '';
        };

        $property = new \ReflectionProperty($class, 'title');
        $attrs = $property->getAttributes(Field::class);

        self::assertCount(1, $attrs);
        $field = $attrs[0]->newInstance();

        self::assertSame('my_label', $field->transKey);
        self::assertTrue($field->searchable);
        self::assertSame(5, $field->order);
    }

    public function testTranslationDomainConstant(): void
    {
        self::assertSame('fields', Field::TRANSLATION_DOMAIN);
    }

    public function testIsBrowsableHook(): void
    {
        self::assertTrue((new Field(filterable: true, widget: Widget::Select))->isBrowsable);
        self::assertTrue((new Field(filterable: true, widget: Widget::Boolean))->isBrowsable);
        self::assertFalse((new Field(filterable: true, widget: Widget::Text))->isBrowsable);
        self::assertFalse((new Field(filterable: true, widget: Widget::Range))->isBrowsable);
        self::assertFalse((new Field())->isBrowsable);
    }
}
