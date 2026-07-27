<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\ColumnWidth;
use PHPUnit\Framework\TestCase;

final class ColumnWidthTest extends TestCase
{
    public function testCasesReturnsFiveCases(): void
    {
        $this->assertCount(5, ColumnWidth::cases());
    }

    public function testFixedCaseValue(): void
    {
        $this->assertSame('fixed', ColumnWidth::Fixed->value);
    }

    public function testPercentCaseValue(): void
    {
        $this->assertSame('percent', ColumnWidth::Percent->value);
    }

    public function testDynamicCaseValue(): void
    {
        $this->assertSame('dynamic', ColumnWidth::Dynamic->value);
    }

    public function testContentCaseValue(): void
    {
        $this->assertSame('content', ColumnWidth::Content->value);
    }

    public function testFlexCaseValue(): void
    {
        $this->assertSame('flex', ColumnWidth::Flex->value);
    }

    public function testFromValidValueFixed(): void
    {
        $this->assertSame(ColumnWidth::Fixed, ColumnWidth::from('fixed'));
    }

    public function testFromValidValuePercent(): void
    {
        $this->assertSame(ColumnWidth::Percent, ColumnWidth::from('percent'));
    }

    public function testFromValidValueDynamic(): void
    {
        $this->assertSame(ColumnWidth::Dynamic, ColumnWidth::from('dynamic'));
    }

    public function testFromValidValueContent(): void
    {
        $this->assertSame(ColumnWidth::Content, ColumnWidth::from('content'));
    }

    public function testFromValidValueFlex(): void
    {
        $this->assertSame(ColumnWidth::Flex, ColumnWidth::from('flex'));
    }

    public function testTryFromValidValue(): void
    {
        $this->assertSame(ColumnWidth::Fixed, ColumnWidth::tryFrom('fixed'));
        $this->assertSame(ColumnWidth::Percent, ColumnWidth::tryFrom('percent'));
        $this->assertSame(ColumnWidth::Dynamic, ColumnWidth::tryFrom('dynamic'));
        $this->assertSame(ColumnWidth::Content, ColumnWidth::tryFrom('content'));
        $this->assertSame(ColumnWidth::Flex, ColumnWidth::tryFrom('flex'));
    }

    public function testTryFromInvalidValueReturnsNull(): void
    {
        $this->assertNull(ColumnWidth::tryFrom('invalid'));
        $this->assertNull(ColumnWidth::tryFrom(''));
        $this->assertNull(ColumnWidth::tryFrom('FIXED'));
    }

    public function testFromThrowsOnInvalidValue(): void
    {
        $this->expectException(\ValueError::class);
        ColumnWidth::from('bad');
    }
}
