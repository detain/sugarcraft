<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Diff\DiffEncoder;
use SugarCraft\CellBuffer\Diff\SgrEmitter;
use SugarCraft\CellBuffer\Diff\MoveCursorOp;
use SugarCraft\CellBuffer\Diff\SetCellOp;
use SugarCraft\CellBuffer\Diff\SetStyleOp;
use SugarCraft\CellBuffer\Style;

/**
 * @covers \SugarCraft\CellBuffer\Diff\SgrEmitter
 */
final class SgrEmitterTest extends TestCase
{
    public function testInvisibleEmitsCode8(): void
    {
        // A style with only ATTR_INVISIBLE renders to a SGR containing the '8' token.
        $style = Style::new(null, null, Style::ATTR_INVISIBLE);
        $buffer = Buffer::new(1, 1)->withCellAt(0, 0, Cell::new('x', $style));
        $ansi = $buffer->toAnsi();

        $this->assertStringContainsString("\x1b[", $ansi);
        $this->assertStringContainsString('8', $ansi);
    }

    public function testBufferAndDiffEncoderEmitIdenticalSgr(): void
    {
        // For a representative style (fg+bg+bold+invisible), both
        // Buffer::toAnsi() and DiffEncoder must produce byte-identical SGR.
        $style = Style::new(0xff0000, 0x0000ff, Style::ATTR_BOLD | Style::ATTR_INVISIBLE);
        $cell = Cell::new('x', $style);

        // --- Buffer::toAnsi() path ---
        $buf = Buffer::new(1, 1)->withCellAt(0, 0, $cell);
        $bufAnsi = $buf->toAnsi();

        // Extract SGR sequence from Buffer output
        preg_match('/(\x1b\[[0-9;]+m)/', $bufAnsi, $bufMatch);
        $this->assertNotEmpty($bufMatch[1], 'Buffer::toAnsi() must emit an SGR sequence');
        $bufSgr = $bufMatch[1];

        // --- DiffEncoder path ---
        $ops = [
            new SetStyleOp($style),
            new MoveCursorOp(0, 0),
            new SetCellOp([$cell]),
        ];
        $encoder = new DiffEncoder();
        $diffAnsi = $encoder->encode($ops);

        // Extract SGR sequence from DiffEncoder output
        preg_match('/(\x1b\[[0-9;]+m)/', $diffAnsi, $diffMatch);
        $this->assertNotEmpty($diffMatch[1], 'DiffEncoder must emit an SGR sequence');
        $diffSgr = $diffMatch[1];

        // Both SGR sequences must be byte-identical
        $this->assertSame($bufSgr, $diffSgr);
    }

    public function testPaletteSlotForegroundsEmitTheCanonicalSlotCodes(): void
    {
        $this->assertSame("\x1b[0;31m", SgrEmitter::emit(new Style(null, null, 0, 1)));
        $this->assertSame("\x1b[0;91m", SgrEmitter::emit(new Style(null, null, 0, 9)));
        $this->assertSame("\x1b[0;38;5;170m", SgrEmitter::emit(new Style(null, null, 0, 170)));
    }

    public function testPaletteSlotBackgroundsEmitTheCanonicalSlotCodes(): void
    {
        $this->assertSame("\x1b[0;44m", SgrEmitter::emit(new Style(null, null, 0, null, 4)));
        $this->assertSame("\x1b[0;104m", SgrEmitter::emit(new Style(null, null, 0, null, 12)));
        $this->assertSame("\x1b[0;48;5;200m", SgrEmitter::emit(new Style(null, null, 0, null, 200)));
    }

    public function testUnderlineColourFollowsTheAttrCodesInBothForms(): void
    {
        $slot = new Style(null, null, Style::ATTR_UNDERLINE, null, null, null, 3);
        $this->assertSame("\x1b[0;4;58;5;3m", SgrEmitter::emit($slot));

        $rgb = new Style(null, null, Style::ATTR_UNDERLINE, null, null, 0x010203);
        $this->assertSame("\x1b[0;4;58;2;1;2;3m", SgrEmitter::emit($rgb));
    }

    public function testTheSlotFormWinsWhenBothRepresentationsAreSet(): void
    {
        // The belt pairing fromSgr() records: slot 1 plus its canonical
        // hex 0xFF0000. Emission must carry the palette code, never the
        // 38;2 belt — a contradictory pairing cannot even be constructed
        // (Style ctor belt law, sugar-cellbuffer-006).
        $both = Style::fromSgr('31');

        $this->assertSame("\x1b[0;31m", SgrEmitter::emit($both));
    }

    public function testTheRgbOnlyPathStaysByteIdentical(): void
    {
        $style = Style::new(0xff0000, 0x0000ff, Style::ATTR_BOLD);

        $this->assertSame("\x1b[0;38;2;255;0;0;48;2;0;0;255;1m", SgrEmitter::emit($style));
    }
}
