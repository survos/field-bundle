<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Tests\Attribute;

use PHPUnit\Framework\TestCase;
use Survos\FieldBundle\Attribute\Map;

final class MapTest extends TestCase
{
    public function testDefaults(): void
    {
        $map = new Map();

        self::assertNull($map->source);
        self::assertNull($map->regex);
        self::assertNull($map->if);
        self::assertNull($map->delim);
        self::assertSame(10, $map->priority);
        self::assertSame([], $map->when);
        self::assertSame([], $map->except);
        self::assertFalse($map->facet);
        self::assertFalse($map->sortable);
        self::assertFalse($map->searchable);
        self::assertFalse($map->translatable);
    }

    public function testExplicitValues(): void
    {
        $map = new Map(
            source: 'title',
            regex: '/name/',
            if: 'isset',
            delim: '|',
            priority: 20,
            when: ['dataset-a'],
            except: ['dataset-b'],
            facet: true,
            sortable: true,
            searchable: true,
            translatable: true,
        );

        self::assertSame('title', $map->source);
        self::assertSame('/name/', $map->regex);
        self::assertSame('isset', $map->if);
        self::assertSame('|', $map->delim);
        self::assertSame(20, $map->priority);
        self::assertSame(['dataset-a'], $map->when);
        self::assertSame(['dataset-b'], $map->except);
        self::assertTrue($map->facet);
        self::assertTrue($map->sortable);
        self::assertTrue($map->searchable);
        self::assertTrue($map->translatable);
    }

    public function testAttributeIsReadableViaReflection(): void
    {
        $class = new class {
            #[Map(source: 'title', searchable: true, priority: 20)]
            public string $title = '';
        };

        $property = new \ReflectionProperty($class, 'title');
        $attrs = $property->getAttributes(Map::class);

        self::assertCount(1, $attrs);
        $map = $attrs[0]->newInstance();

        self::assertSame('title', $map->source);
        self::assertTrue($map->searchable);
        self::assertSame(20, $map->priority);
    }
}
