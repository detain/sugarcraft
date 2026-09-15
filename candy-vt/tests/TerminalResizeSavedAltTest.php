<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * E725: `resize()` must follow the saved alt-state buffer. Before the fix
 * only the active Buffer resized, so leaving DEC 1049/1047 restored a
 * stale-size grid into an already-resized terminal.
 */
final class TerminalResizeSavedAltTest extends TestCase
{
    public function testResizeWhileInFullAltResizesSavedMainBuffer(): void
    {
        $t = Terminal::new(10, 5);
        $t->feed('MAIN');
        $t->enableAltScreen();
        $t->feed('ALT');

        $t->resize(20, 8);

        // Active (alt) screen follows immediately...
        $this->assertSame(20, $t->screen()->cols);
        $this->assertSame(8, $t->screen()->rows);

        // ...and the saved main screen must carry the SAME new dimensions.
        $t->disableAltScreen();
        $screen = $t->screen();
        $this->assertSame(20, $screen->cols, 'saved alt-state buffer kept stale width after resize');
        $this->assertSame(8, $screen->rows, 'saved alt-state buffer kept stale height after resize');
        $this->assertSame('M', $screen->cell(0, 0)->grapheme, 'resize must preserve saved content');
        $this->assertSame('N', $screen->cell(0, 3)->grapheme);
    }

    public function testResizeWhileInNoSaveAltResizesSavedMainBuffer(): void
    {
        $t = Terminal::new(10, 5);
        $t->feed('MAIN');
        $t->feed("\x1b[?1047h"); // DECSET 1047 — swap buffer, save no cursor/SGR.

        $t->resize(20, 8);

        $t->feed("\x1b[?1047l"); // leave alt — restores the saved main buffer.
        $screen = $t->screen();
        $this->assertSame(20, $screen->cols);
        $this->assertSame(8, $screen->rows);
        $this->assertSame('M', $screen->cell(0, 0)->grapheme);
    }

    public function testResizeOutsideAltStaysSingleBuffer(): void
    {
        $t = Terminal::new(10, 5);
        $t->feed('X');

        $t->resize(12, 6);

        $screen = $t->screen();
        $this->assertSame(12, $screen->cols);
        $this->assertSame(6, $screen->rows);
        $this->assertSame('X', $screen->cell(0, 0)->grapheme);
    }
}
