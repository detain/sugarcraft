<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;

/**
 * Erases issued inside a DEC 2026 synchronized-output window must land in
 * the REAL pending queue, in order.
 *
 * The historical bug: csiDispatch took `$pending = $this->syncUpdate ?
 * $this->pendingMutations : null` — a BY-VALUE copy — so EraseHandler
 * appended its mutations to a throw-away local and every ED/EL/ECH/DCH/
 * ICH inside `CSI ?2026h … CSI ?2026l` silently vanished at flush.
 *
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (DEC 2026)
 */
final class SyncOutputEraseTest extends TestCase
{
    private function feed(string $bytes, int $cols = 6, int $rows = 3): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    private function rowGraphemes(ScreenHandler $h, int $row): string
    {
        $s = '';
        for ($c = 0; $c < $h->buffer->cols; $c++) {
            $s .= $h->buffer->cell($row, $c)->grapheme;
        }
        return $s;
    }

    public function testEdInsideSyncWindowSurvivesFlush(): void
    {
        $h = $this->feed("abc\r\ndef\x1b[H\x1b[?2026h\x1b[2J\x1b[?2026l");
        $this->assertSame('      ', $this->rowGraphemes($h, 0), 'ED 2 queued in sync must actually erase');
        $this->assertSame('      ', $this->rowGraphemes($h, 1));
    }

    public function testElInsideSyncWindowSurvivesFlush(): void
    {
        $h = $this->feed("abcdef\x1b[1;3H\x1b[?2026h\x1b[0K\x1b[?2026l");
        $this->assertSame('ab    ', $this->rowGraphemes($h, 0), 'EL 0 from col 2 onward');
    }

    public function testEraseKeepsOrderAgainstQueuedPrints(): void
    {
        // print X → ED 2 → print Y, all inside one sync window: the erase
        // sits BETWEEN the two glyphs in the queue, so only Y survives.
        $h = $this->feed("\x1b[?2026hX\x1b[2JY\x1b[?2026l", cols: 4, rows: 1);
        $this->assertSame(' Y  ', $this->rowGraphemes($h, 0), 'queued order X, erase, Y preserved');
    }

    public function testDchInsideSyncWindowSurvivesFlush(): void
    {
        // "ABCDEF", home, queue DCH 2 → on flush "CDEF" slides left.
        $h = $this->feed("ABCDEF\x1b[H\x1b[?2026h\x1b[2P\x1b[?2026l");
        $this->assertSame('CDEF  ', $this->rowGraphemes($h, 0), 'DCH queued in sync must survive');
    }

    public function testIchInsideSyncWindowSurvivesFlush(): void
    {
        $h = $this->feed("ABCD\x1b[H\x1b[?2026h\x1b[2@\x1b[?2026l");
        $this->assertSame('  ABCD', substr($this->rowGraphemes($h, 0), 0, 6), 'ICH queued in sync must survive');
    }

    public function testEchInsideSyncWindowSurvivesFlush(): void
    {
        $h = $this->feed("abcde\x1b[H\x1b[?2026h\x1b[2X\x1b[?2026l");
        $this->assertSame('  cde', substr($this->rowGraphemes($h, 0), 0, 5));
    }

    public function testErasesFlushedAtomicallyOnSoftResetExit(): void
    {
        // DECSTR while sync is on flushes the queue — the queued ED lands.
        $h = $this->feed("abc\r\ndef\x1b[H\x1b[?2026h\x1b[2J\x1b[!p");
        $this->assertSame('      ', $this->rowGraphemes($h, 0), 'DECSTR flush includes the queued erase');
        $this->assertFalse($h->mode->syncUpdate);
    }

    public function testEraseOutsideSyncStillAppliesDirectly(): void
    {
        // Regression guard for the non-sync branch of the fix.
        $h = $this->feed("abcdef\x1b[H\x1b[1;3H\x1b[K");
        $this->assertSame('ab    ', $this->rowGraphemes($h, 0));
    }
}
