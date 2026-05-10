<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\StyledCell;
use PHPUnit\Framework\TestCase;

final class StyledCellTest extends TestCase
{
    public function testNew(): void
    {
        $cell = StyledCell::new('value', '1;32');
        $this->assertSame('value', $cell->value);
        $this->assertSame('1;32', $cell->style);
    }

    public function testNewWithEmptyStyle(): void
    {
        $cell = StyledCell::new('text');
        $this->assertSame('text', $cell->value);
        $this->assertSame('', $cell->style);
    }

    public function testConstructorWithEmptyStyle(): void
    {
        $cell = new StyledCell('test', '');
        $this->assertSame('test', $cell->value);
        $this->assertSame('', $cell->style);
    }

    public function testWithStyle(): void
    {
        $cell = StyledCell::new('text', '1');
        $cell2 = $cell->withStyle('1;31');

        // Original cell unchanged (immutable)
        $this->assertSame('1', $cell->style);
        $this->assertSame('1;31', $cell2->style);
        $this->assertSame('text', $cell2->value);
    }

    public function testWithStyleReturnsNewInstance(): void
    {
        $cell = StyledCell::new('original');
        $cell2 = $cell->withStyle('1');

        $this->assertNotSame($cell, $cell2);
    }

    public function testToStringScalarValue(): void
    {
        $cell = StyledCell::new(42);
        $this->assertSame('42', (string) $cell);
    }

    public function testToStringStringValue(): void
    {
        $cell = StyledCell::new('hello');
        $this->assertSame('hello', (string) $cell);
    }

    public function testToStringWithStyle(): void
    {
        $cell = StyledCell::new('error', '1;31');
        $result = (string) $cell;

        $this->assertStringStartsWith("\x1b[1;31m", $result);
        $this->assertStringContainsString('error', $result);
        $this->assertStringEndsWith("\x1b[0m", $result);
    }

    public function testToStringWithEmptyStyle(): void
    {
        $cell = StyledCell::new('plain', '');
        $this->assertSame('plain', (string) $cell);
    }

    public function testToStringWithObjectHavingToString(): void
    {
        $obj = new class {
            public function __toString(): string
            {
                return 'CustomToString';
            }
        };
        $cell = StyledCell::new($obj);
        $this->assertSame('CustomToString', (string) $cell);
    }

    public function testToStringWithObjectHavingToStringAndStyle(): void
    {
        $obj = new class {
            public function __toString(): string
            {
                return 'StyledObject';
            }
        };
        $cell = StyledCell::new($obj, '1;33');
        $result = (string) $cell;

        $this->assertStringStartsWith("\x1b[1;33m", $result);
        $this->assertStringContainsString('StyledObject', $result);
        $this->assertStringEndsWith("\x1b[0m", $result);
    }

    public function testToStringWithNonScalarWithoutToString(): void
    {
        $cell = StyledCell::new(['array', 'data']);
        $this->assertSame('', (string) $cell);
    }

    public function testToStringWithNull(): void
    {
        $cell = StyledCell::new(null);
        $this->assertSame('', (string) $cell);
    }

    public function testToStringWithFloat(): void
    {
        $cell = StyledCell::new(3.14159);
        $this->assertSame('3.14159', (string) $cell);
    }

    public function testToStringWithBool(): void
    {
        $cellTrue = StyledCell::new(true);
        $cellFalse = StyledCell::new(false);

        $this->assertSame('1', (string) $cellTrue);
        $this->assertSame('', (string) $cellFalse);
    }

    public function testImmutabilityWithWithStyle(): void
    {
        $cell = StyledCell::new('text', '1');
        $cell2 = $cell->withStyle('1;32');
        $cell3 = $cell2->withStyle('1;34');

        $this->assertSame('1', $cell->style);
        $this->assertSame('1;32', $cell2->style);
        $this->assertSame('1;34', $cell3->style);
    }
}