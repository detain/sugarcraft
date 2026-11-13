<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\StyledCell;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;
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

    public function testConstructorIsPrivateAndNewIsTheDoor(): void
    {
        // ST-49 (finding 060): the ctor went private; ::new() covers every
        // caller (census: examples + tests only, zero external direct-new).
        $ctor = (new \ReflectionClass(StyledCell::class))->getConstructor();
        $this->assertNotNull($ctor);
        $this->assertTrue($ctor->isPrivate(), 'StyledCell must not be constructible outside the class');

        $cell = StyledCell::new('test', '');
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
    // ---- render routing (audit ST-50, finding 061) -------------------------

    public function testStyledNullRendersTheMissingIndicatorLikeRawNull(): void
    {
        $columns = [Column::new('v', 'Value', 8)];
        $styled = Table::fromColumns($columns)
            ->withRows([Row::new(RowData::from(['v' => StyledCell::new(null, '31')]))]);
        $raw = Table::fromColumns($columns)
            ->withRows([Row::new(RowData::from(['v' => null]))]);

        $this->assertSame($raw->view(), $styled->view(), 'wrapper-independent no-value shape');
        $this->assertStringContainsString('-', $raw->view());
    }

    public function testStyledNullHonoursACustomIndicator(): void
    {
        $view = Table::fromColumns([Column::new('v', 'Value', 8)])
            ->withRows([Row::new(RowData::from(['v' => StyledCell::new(null)]))])
            ->withMissingIndicator('(empty)')
            ->view();

        $this->assertStringContainsString('(empty)', $view);
    }

    public function testStyledFalseKeepsItsLegacyEmptyCast(): void
    {
        $view = Table::fromColumns([Column::new('v', 'Value', 8)])
            ->withRows([Row::new(RowData::from(['v' => StyledCell::new(false)]))])
            ->view();

        $this->assertStringNotContainsString('-', $view, 'false is a value, not a missing cell');
    }

    public function testMissingValueMeasuresItsIndicatorHeight(): void
    {
        // The height pass must budget what the paint pass prints: a wide
        // custom indicator in a narrow multiline column takes real rows —
        // whether the cell is null or styled-null.
        $styled = Table::fromColumns([Column::new('v', 'V', 4)->withWrapMode(\SugarCraft\Table\WrapMode::Character)])
            ->withRows([Row::new(RowData::from(['v' => StyledCell::new(null)]))])
            ->withMultilineMode()
            ->withCellPadding(0)
            ->withMissingIndicator('(empty)');
        $raw = $styled->withRows([Row::new(RowData::from(['v' => null]))]);

        $lines = explode("\n", $styled->view());
        $this->assertSame($raw->view(), $styled->view());
        $this->assertCount(count(explode("\n", $raw->view())), $lines);
        // Header(1) + separator(1) + indicator wrapped to (e / mpty / ) rows:
        // width 4 forces three content lines for a 7-char indicator.
        $body = array_slice($lines, 2);
        $this->assertGreaterThanOrEqual(3, count($body));
        $this->assertStringContainsString('emp', $body[1]);
    }

}