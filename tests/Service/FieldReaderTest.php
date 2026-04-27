<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Tests\Service;

use PHPUnit\Framework\TestCase;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Enum\Widget;
use Survos\FieldBundle\Model\FieldDescriptor;
use Survos\FieldBundle\Service\FieldReader;

final class FieldReaderTest extends TestCase
{
    private FieldReader $reader;

    protected function setUp(): void
    {
        $this->reader = new FieldReader();
    }

    public function testReadsFieldAttributes(): void
    {
        $class = new class {
            #[Field(searchable: true, sortable: true, order: 10)]
            public string $title = '';

            #[Field(filterable: true, widget: Widget::Select, facet: true, order: 20)]
            public string $status = '';

            public string $ignored = '';
        };

        $descriptors = $this->reader->getDescriptors($class::class);

        self::assertCount(2, $descriptors);
        self::assertSame('title',  $descriptors[0]->name);
        self::assertSame('status', $descriptors[1]->name);
    }

    public function testOrderSorting(): void
    {
        $class = new class {
            #[Field(order: 50)]
            public string $b = '';

            #[Field(order: 10)]
            public string $a = '';
        };

        $descriptors = $this->reader->getDescriptors($class::class);
        self::assertSame('a', $descriptors[0]->name);
        self::assertSame('b', $descriptors[1]->name);
    }

    public function testGetDescriptor(): void
    {
        $class = new class {
            #[Field(filterable: true, widget: Widget::Boolean)]
            public bool $active = true;
        };

        $d = $this->reader->getDescriptor($class::class, 'active');

        self::assertInstanceOf(FieldDescriptor::class, $d);
        self::assertSame(Widget::Boolean, $d->widget);
        self::assertSame('bool', $d->type);
    }

    public function testTranslationKeyFallsBackToPropertyName(): void
    {
        $class = new class {
            #[Field]
            public string $accountType = '';
        };

        $d = $this->reader->getDescriptor($class::class, 'accountType');
        self::assertSame('accountType', $d?->getTranslationKey());
        self::assertSame('Account Type', $d?->getFallbackLabel());
        self::assertSame('fields', $d?->getTranslationDomain());
    }

    public function testExplicitTransKeyOverride(): void
    {
        $class = new class {
            #[Field(transKey: 'tenant.status')]
            public string $status = '';
        };

        $d = $this->reader->getDescriptor($class::class, 'status');
        self::assertSame('tenant.status', $d?->getTranslationKey());
        self::assertSame('Tenant.status', $d?->getFallbackLabel());
    }

    public function testGetDescriptorReturnsNullForMissingProperty(): void
    {
        $class = new class {
            #[Field]
            public string $name = '';
        };

        self::assertNull($this->reader->getDescriptor($class::class, 'nonexistent'));
    }

    public function testResolvedWidgetInfersBooleanFromType(): void
    {
        $class = new class {
            #[Field(filterable: true)]
            public bool $active = true;
        };

        $d = $this->reader->getDescriptor($class::class, 'active');
        self::assertSame(Widget::Boolean, $d?->resolvedWidget());
    }

    public function testResultsAreCached(): void
    {
        $class = new class {
            #[Field(searchable: true)]
            public string $name = '';
        };

        $first  = $this->reader->getDescriptors($class::class);
        $second = $this->reader->getDescriptors($class::class);
        self::assertSame($first, $second);
    }
}
