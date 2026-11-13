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
        $this->assertSame('', Sgr::normalize('', 'style'));
        $this->assertNull(Sgr::normalize(null, 'style'));
    }

    public function testTheDoorAcceptsWellFormedParameterStrings(): void
    {
        foreach (['1', '31;1', '38;5;196', '38;2;255;0;0', '255', '1;255;38;2;0;0;0'] as $ok) {
            $this->assertSame($ok, Sgr::normalize($ok, 'style'), 'canonical input is returned untouched');
        }
    }

    public function testTheDoorRefusesMalformedShapesWithoutEchoingTheValue(): void
    {
        foreach (['1;;2', ';1', '1;', '9999', '256', '999', '007', '1;2m', "\x1b[31", 'abc'] as $bad) {
            try {
                Sgr::normalize($bad, 'myProp');
                $this->fail('door accepted ' . addcslashes($bad, "\x00..\x1f"));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('myProp must be a bare SGR parameter string', $e->getMessage());
                $this->assertStringNotContainsString("\x1b", $e->getMessage());
                $this->assertStringNotContainsString('1;2m', $e->getMessage());
            }
        }
    }

    // ---- colon sub-param canonicalization (finding 035, FBX-32) ----------

    public function testTheDoorCanonicalizesTheColonSubParamGrammar(): void
    {
        $cases = [
            // T.416 truecolor with colorspace id → dropped (the ';' grammar
            // the renderer speaks carries no CS slot)
            '38:2:0:10:20:30' => '38;2;10;20;30',
            '48:2:0:10:20:30' => '48;2;10;20;30',
            '38:2::10:20:30'  => '38;2;10;20;30',   // empty CS, same shape
            '38:2:10:20:30'   => '38;2;10;20;30',   // CS-less 5-part form
            '38:5:196'        => '38;5;196',
            '48:5:244'        => '48;5;244',
            '1;38:2:1:2:3;31' => '1;38;2;1;2;3;31', // mixed mid-string
        ];
        foreach ($cases as $colon => $canonical) {
            $this->assertSame($canonical, Sgr::normalize($colon, 'style'), $colon . ' must canonicalize');
        }
    }

    public function testTheDoorRefusesColonGrammarItCouldNotRead(): void
    {
        // Colons anywhere the parser has no arm are smuggling channels: non
        // -38/48 introducers, unknown kinds, wrong arity, out-of-range parts.
        foreach (['31:1', '39:5:1', '22:0', '38:9:5', '38:5:256', '38:5:1:2', '38:2:10:20', '38:2:1:2:3:4:5', '38::5', '38:2:x:1:2:3'] as $bad) {
            try {
                Sgr::normalize($bad, 'myProp');
                $this->fail('door accepted ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith('myProp must be a bare SGR parameter string', $e->getMessage());
                $this->assertStringNotContainsString($bad, $e->getMessage(), 'the refused value is never echoed');
            }
        }
    }

    public function testNormalizeIsIdempotentOnCanonicalOutput(): void
    {
        foreach (['38:2:0:10:20:30', '38:5:196', '1;38:2:1:2:3;31'] as $colon) {
            $once = Sgr::normalize($colon, 'style');
            $this->assertSame($once, Sgr::normalize($once, 'style'), 'the door must be a fixed point on its own output');
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

    public function testEverySurfaceAcceptsAndCanonicalizesTheColonForm(): void
    {
        // The door is the single ingress: whatever surface carries the
        // colon form, the bytes that reach frames are semicolon grammar,
        // proven through the rendered output itself (no internal getters).
        $colon = '38:2:0:10:20:30';
        FlexItem::new('x')->withStyle($colon);
        Column::make('T', 5)->withStyle($colon);
        $frame = Table::new()
            ->addColumn(Column::new('H', 3)->withStyle($colon))
            ->withHeaderStyle($colon)
            ->withCursorStyle($colon)
            ->addRow(['ab'])
            ->render();
        $this->assertStringContainsString("\x1b[38;2;10;20;30m", $frame);
        $this->assertStringNotContainsString("\x1b[38:", $frame, 'a colon must never ride into a frame');
    }
}
