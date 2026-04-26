<?php

declare(strict_types=1);

namespace Survos\FieldBundle\Tests\Enum;

use PHPUnit\Framework\TestCase;
use Survos\FieldBundle\Enum\Widget;

final class WidgetTest extends TestCase
{
    public function testValues(): void
    {
        self::assertSame('text',    Widget::Text->value);
        self::assertSame('select',  Widget::Select->value);
        self::assertSame('range',   Widget::Range->value);
        self::assertSame('date',    Widget::Date->value);
        self::assertSame('boolean', Widget::Boolean->value);
    }

    public function testFromValue(): void
    {
        self::assertSame(Widget::Range, Widget::from('range'));
    }

    public function testAllCasesPresent(): void
    {
        $cases = array_map(fn ($c) => $c->value, Widget::cases());
        self::assertContains('text',    $cases);
        self::assertContains('select',  $cases);
        self::assertContains('range',   $cases);
        self::assertContains('date',    $cases);
        self::assertContains('boolean', $cases);
    }
}
