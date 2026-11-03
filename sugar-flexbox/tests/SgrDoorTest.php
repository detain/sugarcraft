<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Flex\FlexItem;
use SugarCraft\Flexbox\Support\Sgr;
use SugarCraft\Flexbox\Table\Column;
use SugarCraft\Flexbox\Table\Table;

/**
 * The shared SGR door (src/Support/Sgr.php) that FlexItem, Column and Table
 * setters now delegate to: one grammar, one refusal message shape, and the
 * offending value is never echoed back (it is the exact bytes the door keeps
 * out — exception text reaches terminals).
 */
final class SgrDoorTest extends TestCase
{
    public function testTheDoorAcceptsTheEmptyAndNullNoStyle(): void
    {
        Sgr::assertParameterString('', 'style');
        Sgr::assertParameterString(null, 'style');
        $this->assertTrue(true); // reached without throwing
    }

    public function testTheDoorAcceptsWellFormedParameterStrings(): void
    {
        foreach (['1', '31;1', '38;5;196', '38;2;255;0;0', '999;999'] as $ok) {
            Sgr::assertParameterString($ok, 'style');
        }
        $this->assertTrue(true);
    }

    public function testTheDoorRefusesMalformedShapesWithoutEchoingTheValue(): void
    {
        foreach (['1;;2', ';1', '1;', '9999', '1;2m', "\x1b[31", 'abc'] as $bad) {
            try {
                Sgr::assertParameterString($bad, 'myProp');
                $this->fail('door accepted ' . addcslashes($bad, "\x00..\x1f"));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('myProp must be a bare SGR parameter string', $e->getMessage());
                $this->assertStringNotContainsString("\x1b", $e->getMessage());
                $this->assertStringNotContainsString('1;2m', $e->getMessage());
            }
        }
    }

    public function testFlexItemStyleKnobIsNamedInTheRefusal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('FlexItem style must be a bare SGR parameter string');
        FlexItem::new('x')->withStyle('9999');
    }

    public function testColumnStyleKnobIsNamedInTheRefusal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Column style must be a bare SGR parameter string');
        Column::make('T', 5)->withStyle('1;;2');
    }

    public function testTableCursorStyleKnobIsNamedInTheRefusal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table cursor style must be a bare SGR parameter string');
        Table::new()->withCursorStyle("31\x1b");
    }

    public function testTableHeaderStyleKnobIsNamedInTheRefusal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Table header style must be a bare SGR parameter string');
        Table::new()->withHeaderStyle('nope');
    }

    public function testAllThreeSurfacesAcceptFullExtendedSgr(): void
    {
        $item = FlexItem::new('x')->withStyle('38;2;255;0;0');
        $col = Column::make('T', 5)->withStyle('38;5;196');
        $t = Table::new()->withCursorStyle('1;31')->withHeaderStyle('4');
        $this->assertSame('x', $item->content);
        $this->assertSame('T', $col->title);
        $this->assertSame(0, $t->rowCount());
    }
}
