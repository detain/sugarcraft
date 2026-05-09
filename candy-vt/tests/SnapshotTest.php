<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * Feeds the captured `.ansi` byte fixtures through a Terminal and
 * asserts known cell + cursor + mode positions. Each fixture is small
 * and committed as a binary file (see `tests/fixtures/.gitattributes`).
 */
final class SnapshotTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/fixtures/' . $name;
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            $this->fail("missing fixture: $path");
        }
        return $bytes;
    }

    private function row(Terminal $term, int $row): string
    {
        foreach ($term->screen()->lines() as $i => $line) {
            if ($i === $row) {
                return $line;
            }
        }
        return '';
    }

    public function testSgrRainbowFixture(): void
    {
        $term = Terminal::create(cols: 80, rows: 1);
        $term->feed($this->fixture('sgr-rainbow.ansi'));
        $s = $term->screen();

        // 'R' painted with 16-color red foreground (kind=1, value=1)
        $this->assertSame('R', $s->cell(0, 0)->grapheme);
        $this->assertSame(1, $s->cell(0, 0)->foreground()->kind);
        $this->assertSame(1, $s->cell(0, 0)->foreground()->value);

        // 'G' green
        $this->assertSame('G', $s->cell(0, 1)->grapheme);
        $this->assertSame(2, $s->cell(0, 1)->foreground()->value);

        // 'B' blue
        $this->assertSame('B', $s->cell(0, 2)->grapheme);
        $this->assertSame(4, $s->cell(0, 2)->foreground()->value);

        // After CSI 0 m the next chunk is bold yellow (33)
        $this->assertSame('B', $s->cell(0, 3)->grapheme);
        $this->assertTrue($s->cell(0, 3)->sgr->bold);

        // Truecolor at the end (RGB 255,128,0 = orange)
        $tcCell = null;
        foreach ([7, 8, 9, 10, 11, 12, 13] as $col) {
            $cell = $s->cell(0, $col);
            if ($cell->foreground()?->kind === 3) {
                $tcCell = $cell;
                break;
            }
        }
        $this->assertNotNull($tcCell);
        $this->assertSame((255 << 16) | (128 << 8) | 0, $tcCell->foreground()->value);
    }

    public function testCursorMovesFixture(): void
    {
        $term = Terminal::create(cols: 20, rows: 5);
        $term->feed($this->fixture('cursor-moves.ansi'));

        // After ED 2J, screen was cleared, then HVP placed text.
        $this->assertSame('!                   ', $this->row($term, 0));
        $this->assertSame('    Hello           ', $this->row($term, 1));
        $this->assertSame('    World           ', $this->row($term, 2));
        // After the final '!' write, cursor advanced from (0,0) to (0,1).
        $this->assertSame(0, $term->cursor()->row);
        $this->assertSame(1, $term->cursor()->col);
    }

    public function testOscTitleAndHyperlinkFixture(): void
    {
        $term = Terminal::create(cols: 80, rows: 1);
        $term->feed($this->fixture('osc-title-link.ansi'));

        $this->assertSame('Demo', $term->windowTitle());

        $s = $term->screen();
        // 'W' of "Welcome" — outside the hyperlink span.
        $this->assertSame('W', $s->cell(0, 0)->grapheme);
        $this->assertNull($s->cell(0, 0)->hyperlink);

        // 'c' of "click" — first char inside the hyperlink span.
        $clickCol = strpos('Welcome ', 'click') === false ? 8 : strpos('Welcome ', 'click');
        $cell = $s->cell(0, 8);
        $this->assertSame('c', $cell->grapheme);
        $this->assertNotNull($cell->hyperlink);
        $this->assertSame('https://example.com', $cell->hyperlink->uri);
        $this->assertSame('1', $cell->hyperlink->id);

        // ' ' after "click" — outside the span again.
        $this->assertNull($s->cell(0, 13)->hyperlink);
    }

    public function testBubbleteaCounterFixture(): void
    {
        $term = Terminal::create(cols: 20, rows: 3);
        $term->feed($this->fixture('bubbletea-counter.ansi'));

        // Cursor was hidden via ?25l.
        $this->assertFalse($term->mode()->cursorVisible);
        $this->assertFalse($term->cursor()->visible);

        // Row 0: "Counter: 0" with the 0 overwritten by '1' at col 9.
        $this->assertSame('Counter: 1          ', $this->row($term, 0));
        $this->assertSame('Press + or -        ', $this->row($term, 1));
    }

    public function testCjkJpFixture(): void
    {
        $term = Terminal::create(cols: 30, rows: 1);
        $term->feed($this->fixture('cjk-jp.ansi'));

        $s = $term->screen();
        // "名前: 日本語" — 名 (2 cells), 前 (2), ': ' (2 ASCII), 日(2), 本(2), 語(2)
        $this->assertSame('名', $s->cell(0, 0)->grapheme);
        $this->assertTrue($s->cell(0, 1)->continuation);
        $this->assertSame('前', $s->cell(0, 2)->grapheme);
        $this->assertTrue($s->cell(0, 3)->continuation);
        $this->assertSame(':', $s->cell(0, 4)->grapheme);
        $this->assertSame(' ', $s->cell(0, 5)->grapheme);
        $this->assertSame('日', $s->cell(0, 6)->grapheme);
        $this->assertTrue($s->cell(0, 7)->continuation);
        $this->assertSame('本', $s->cell(0, 8)->grapheme);
        $this->assertTrue($s->cell(0, 9)->continuation);
        $this->assertSame('語', $s->cell(0, 10)->grapheme);
        $this->assertTrue($s->cell(0, 11)->continuation);

        $this->assertSame(12, $term->cursor()->col);
    }
}
