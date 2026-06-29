<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffEncoder;
use SugarCraft\Buffer\Diff\SgrEmitter;
use SugarCraft\Buffer\Diff\MoveCursorOp;
use SugarCraft\Buffer\Diff\SetCellOp;
use SugarCraft\Buffer\Diff\SetStyleOp;
use SugarCraft\Buffer\Style;

/**
 * @covers \SugarCraft\Buffer\Diff\SgrEmitter
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
}
