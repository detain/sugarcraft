<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * `clone` of the emulator must produce a genuinely independent machine.
 *
 * PHP's default object clone is shallow, and ScreenHandler had no
 * __clone, so a cloned terminal kept WRITING THROUGH into the original's
 * Buffer grid and Scrollback ring — the snapshot/rewind idiom silently
 * corrupted the very state it was taken to preserve. ScreenHandler::__clone
 * now deep-copies the two mutable aggregates (buffer, scrollback, and the
 * parked alt-screen buffer); the immutable value objects stay shared on
 * purpose.
 */
final class CloneIsolationTest extends TestCase
{
    public function testHandlerCloneBuffersDiverge(): void
    {
        $orig = new ScreenHandler(new Buffer(8, 2));
        (new Parser($orig))->feed('AAAA');

        $clone = clone $orig;
        (new Parser($orig))->feed('XXXX');
        (new Parser($clone))->feed('YYYY');

        $this->assertSame('AAAAXXXX', $this->row($orig), 'original keeps only its own writes');
        $this->assertSame('AAAAYYYY', $this->row($clone), 'clone keeps only its own writes');
    }

    public function testTerminalFacadeCloneDiverges(): void
    {
        $t = Terminal::new(8, 3);
        $t->feed('hello');

        $u = clone $t;
        $t->feed('!!!');
        $u->feed('???');

        $this->assertSame('hello!!!', $this->screenRow($t, 0));
        $this->assertSame('hello???', $this->screenRow($u, 0));
    }

    public function testCloneDoesNotShareScrollbackRing(): void
    {
        $t = Terminal::new(4, 2);
        $t->feed("11\r\n22\r\n33");  // one full-screen scroll pushed "11"
        $this->assertSame(1, $t->screen()->scrollback()?->count());

        $u = clone $t;               // ring snapshot: ['11'] on both sides

        $t->feed("\r\n44");          // pushes row0 "22"
        $t->feed("\r\n55");          // pushes row0 "33"
        $u->feed("\r\nzz");          // pushes the CLONE's row0 "22" — t's growth must be invisible here

        $this->assertSame(3, $t->screen()->scrollback()?->count(), 'original ring grew independently');
        $this->assertSame(2, $u->screen()->scrollback()?->count(), 'clone ring grew independently');
        $this->assertSame('33', trim($this->graphemes($t->screen()->scrollback()->at(2) ?? [])));
        $this->assertSame('22', trim($this->graphemes($u->screen()->scrollback()->at(1) ?? [])));
    }

    public function testCloneInsideAltScreenCopiesParkedMainBuffer(): void
    {
        $t = Terminal::new(8, 2);
        $t->feed('MAIN');
        $t->feed("\x1b[?1049hALT!");

        $u = clone $t;
        $t->feed("\x1b[?1049l\x1b[H");  // back to main, home, overwrite
        $t->feed('####');
        $u->feed("\x1b[?1049l\x1b[H");
        $u->feed('****');

        $this->assertSame('####', substr($this->screenRow($t, 0), 0, 4), 'original main buffer');
        $this->assertSame('****', substr($this->screenRow($u, 0), 0, 4), 'clone main buffer — parked buffer was copied');
    }

    public function testQueuedSyncMutationsAreNotSharedAfterClone(): void
    {
        // The pending queue is an array of immutable Cells: the clone gets
        // its own copy, so flushing one side cannot starve the other.
        $t = Terminal::new(8, 2);
        $t->feed("\x1b[?2026hQQ");
        $u = clone $t;
        $t->feed("\x1b[?2026l");
        $u->feed("\x1b[?2026l");

        $this->assertSame('QQ', substr($this->screenRow($t, 0), 0, 2));
        $this->assertSame('QQ', substr($this->screenRow($u, 0), 0, 2));
    }

    public function testImmutableStateIsSafeToShare(): void
    {
        // Documenting the deliberate half: cursor/Sgr/Mode are value
        // objects — a clone starts identical, then diverges only when one
        // side REPLACES them.
        $t = Terminal::new(8, 2);
        $t->feed("\x1b[31m\x1b[2;3H");
        $u = clone $t;

        $this->assertTrue($t->cursor()->equals($u->cursor()), 'clone starts at the same position');
        $t->feed('X');
        $this->assertSame(1, $u->cursor()->row, 'original movement does not drag the clone');
        $this->assertSame(2, $u->cursor()->col);
    }

    private function row(ScreenHandler $h): string
    {
        $s = '';
        for ($c = 0; $c < $h->buffer->cols; $c++) {
            $s .= $h->buffer->cell(0, $c)->grapheme;
        }
        return $s;
    }

    private function screenRow(Terminal $t, int $row): string
    {
        $screen = $t->screen();
        $s = '';
        for ($c = 0; $c < $screen->cols; $c++) {
            $s .= $screen->cell($row, $c)->grapheme;
        }
        return $s;
    }

    /** @param array<int, \SugarCraft\Vt\Cell> $cells */
    private function graphemes(array $cells): string
    {
        return implode('', array_map(static fn ($c): string => $c->grapheme, $cells));
    }
}
