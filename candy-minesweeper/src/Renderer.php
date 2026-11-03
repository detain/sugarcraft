<?php

declare(strict_types=1);

namespace SugarCraft\Minesweeper;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\Util\Color;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style as SugarStyle;

/**
 * Pure view function: takes a {@see Game} and returns the framebuffer
 * string. No I/O, no caching — every call rebuilds from scratch so
 * snapshot tests can assert against the output directly.
 *
 * Rendering uses a {@see Buffer} for the minefield: each cell is
 * zone-tagged via {@see Mark::zone()} using "cell:$row:$col" so
 * {@see Scanner::hit()} can resolve mouse coordinates to cell
 * positions on click/flag events.
 *
 * Mirrors maxpaulus43/go-sweep — Minesweeper renderer.
 */
final class Renderer
{
    // Adjacent count → colour (0xRRGGBB).
    private const ADJ_COLORS = [
        1 => 0x7dd3fc,  // sky blue
        2 => 0x6ee7b7,  // mint
        3 => 0xfde68a,  // lemon
        4 => 0xa78bfa,  // grape
        5 => 0xff8caa,  // pink
        6 => 0xfcd5b4,  // peach
        7 => 0xfb923c,  // orange
        8 => 0xff5f87,  // hot pink
    ];

    /**
     * Geometry coupling between {@see frame()} and mouse hit-testing: the
     * rounded border costs 1 cell and the padding(0,1) layer 1 more, so the
     * first interior cell glyph sits at 0-based terminal coordinates
     * (2, 1). {@see Game::onMouse()} subtracts these constants to translate
     * 1-based MouseMsg positions into the Scanner's 1-based interior
     * coordinates — and {@see frame()} indents the status/footer by the
     * column offset so text lines up with the first interior column. Any
     * frame change (double border, wider padding) moves the click mapping
     * with it; it cannot silently drift.
     */
    public const INTERIOR_COL_OFFSET = 2;
    public const INTERIOR_ROW_OFFSET = 1;

    public static function render(Game $g): string
    {
        return self::frame($g, self::interior($g, mark: false));
    }

    /**
     * Build the minefield ANSI string.
     *
     * When $mark is true each glyph is wrapped in a {@see Mark::zone()}
     * sentinel for {@see Scanner} hit-testing. Those sentinels embed a
     * plain-ASCII id ("cell:r:c") that {@see Scanner} skips but that the
     * Buffer/Width layer would otherwise count as visible width — which
     * leaks the id text on screen and inflates the border box. So the
     * displayed board is always built unmarked; only the throwaway copy
     * fed to the Scanner carries zone sentinels.
     */
    private static function interior(Game $g, bool $mark): string
    {
        $b = $g->board;
        $boardBuf = Buffer::new($b->width, $b->height);

        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $boardBuf = $boardBuf->withCellAt($x, $y, self::cellBufferCell($b, $x, $y, $g->cursorX, $g->cursorY, $mark));
            }
        }

        return $boardBuf->toAnsi();
    }

    /**
     * Wrap the minefield interior in the rounded border + status + help
     * footer that make up the full view.
     */
    private static function frame(Game $g, string $interior): string
    {
        $framed = SugarStyle::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($interior);

        $status = self::status($g);
        // Indent matches INTERIOR_COL_OFFSET — border cell + horizontal
        // padding cell — so status and footer line up under the first
        // interior column. Each footer line carries it, not just the first.
        $indent = str_repeat(' ', self::INTERIOR_COL_OFFSET);
        // Every bound key names itself here — the footer is the app's only
        // discoverability surface (there is no separate help screen). Values
        // ship translated in all 16 locales; each line stays under 80 cells
        // so even the 2×2 board's view is not clipped on a stock terminal.
        $help = implode("\n" . $indent, [
            Lang::t('ui.help_moves'),
            Lang::t('ui.help_board'),
            Lang::t('ui.help_session'),
        ]);
        // M05 toast: save/load/restart outcomes (and the boot resume hint)
        // ride their own line ABOVE the status, so a load that lands on a
        // lost board still shows the boom/cleared banner underneath it.
        $toast = $g->notice === null
            ? ''
            : $indent . SugarStyle::new()->foreground(Color::hex('#fde68a'))->render('» ' . $g->notice) . "\n";

        return $framed . "\n" . $toast . $indent . $status . "\n" . $indent .
               SugarStyle::new()->foreground(Color::hex('#7d6e98'))->render($help) . "\n";
    }

    /**
     * Build a Buffer cell for the minefield at (col, row).
     *
     * When $mark is true the glyph is zone-tagged with
     * Mark::zone("cell:$row:$col", $glyph) so Scanner::hit() can map
     * mouse coordinates back to a cell.
     */
    private static function cellBufferCell(Board $b, int $col, int $row, int $cx, int $cy, bool $mark = false): \SugarCraft\CellBuffer\Cell
    {
        $cell = $b->cell($col, $row);
        if ($cell === null) {
            return \SugarCraft\CellBuffer\Cell::new(' ');
        }

        [$glyph, $fg, $bg, $attrs] = self::cellStyleData($cell, $b, $col, $row, $cx, $cy);

        $content = $mark ? Mark::zone("cell:{$row}:{$col}", $glyph) : $glyph;
        $style = Style::new($fg, $bg, $attrs);

        return \SugarCraft\CellBuffer\Cell::new($content, $style);
    }

    /**
     * @return array{0:string, 1:?int, 2:?int, 3:int} glyph, fg, bg, attrs
     */
    private static function cellStyleData(Cell $cell, Board $b, int $col, int $row, int $cx, int $cy): array
    {
        // Post-mortem legibility (M21): once the board is LOST, the overlay
        // must grade the player's own work. A flag sitting on a safe cell was
        // wrong — it wears '✗' instead of 'F' so a correct flag (still gold F)
        // and a wrong one are never the same glyph. Mines the player missed
        // keep the '*' reveal but light up in the mine red instead of the dim
        // hidden-dot grey, which on a lost board is information the overlay
        // owes them. Mid-play nothing changes: flags stay 'F' and hidden
        // cells stay dots — no spoilers before the boom.
        $wrongFlag  = $b->exploded && $cell->flagged && !$cell->mine;
        $missedMine = $b->exploded && $cell->mine && !$cell->flagged && !$cell->revealed;

        $glyph = match (true) {
            $wrongFlag    => '✗',
            $cell->flagged => 'F',
            !$cell->revealed && ($b->exploded || $b->isWon()) && $cell->mine => '*',
            !$cell->revealed => '·',
            $cell->mine     => '*',
            $cell->adjacent === 0 => ' ',
            default => (string) $cell->adjacent,
        };

        $fg = null;
        $bg = null;
        $attrs = 0;

        if ($cell->revealed && $cell->mine) {
            $fg = 0xff5f87;
            $attrs = Style::ATTR_BOLD;
        } elseif ($wrongFlag) {
            $fg = 0x7d6e98;
        } elseif ($cell->flagged) {
            $fg = 0xfde68a;
            $attrs = Style::ATTR_BOLD;
        } elseif ($missedMine) {
            $fg = 0xff5f87;
        } elseif ($cell->revealed && $cell->adjacent > 0) {
            $fg = self::ADJ_COLORS[$cell->adjacent] ?? 0xffffff;
        } elseif (!$cell->revealed) {
            $fg = 0x5a4a78;
        }

        // Cursor highlight: reverse foreground/background.
        if ($col === $cx && $row === $cy) {
            $attrs |= Style::ATTR_REVERSE;
        }

        return [$glyph, $fg, $bg, $attrs];
    }

    /**
     * Scan a throwaway marked copy of the minefield into a ready-to-hit-test
     * Scanner. Single derivation point for every consumer ({@see
     * renderWithScanner()}, {@see resolveClick()}) so the zone layout the
     * hit-tests run on is always the one the renderer actually emitted.
     */
    private static function scanner(Game $g): Scanner
    {
        return Scanner::new()->scan(self::interior($g, mark: true));
    }

    /**
     * Render the minefield view and return a ready-to-use Scanner so
     * callers can hit-test clicks.
     *
     * The displayed string is unmarked (see {@see interior()} for why);
     * zone sentinels live only on the throwaway copy fed to the Scanner.
     * Both share the same cell layout, so the Scanner's interior-relative
     * coordinates line up with what {@see resolveClick()} resolves.
     *
     * @return array{0:string, 1:Scanner}
     */
    public static function renderWithScanner(Game $g): array
    {
        return [self::frame($g, self::interior($g, mark: false)), self::scanner($g)];
    }

    /**
     * Resolve a mouse click at (col, row) to a cell coordinate.
     *
     * Hit-testing runs over {@see scanner()}'s zone map ("cell:$row:$col"
     * ids). Returns null if the click was outside any cell zone, and — the
     * defensive half — also when a zone is hit whose id does not match the
     * cell pattern: a zone id is a string, and a mismatch must resolve to
     * nothing rather than a guessed coordinate.
     *
     * $scanner lets a caller that already holds the scanned board (tests
     * pinning the id-mismatch guard, integrators reusing one scan across
     * several clicks) pass it in; null scans a fresh copy.
     *
     * @return array{0:int,1:int}|null col, row of the hit cell
     */
    public static function resolveClick(Game $g, int $col, int $row, ?Scanner $scanner = null): ?array
    {
        $zone = ($scanner ?? self::scanner($g))->hit($col, $row);

        if ($zone === null) {
            return null;
        }

        // Zone id format: "cell:$row:$col".
        if (preg_match('/^cell:(\d+):(\d+)$/', $zone->id, $m)) {
            return [(int) $m[2], (int) $m[1]];  // col, row
        }

        return null;
    }

    private static function status(Game $g): string
    {
        $b = $g->board;
        if ($b->exploded || $b->isWon()) {
            // M06: the verdict line carries the frozen final time — the
            // clock stops at the transition frame, so elapsedSeconds is the
            // result — and a preset win that beats the stored best wears a
            // NEW BEST badge. A board with no frozen time (a hand-built or
            // loaded-finished fixture predating the stamp) keeps the bare
            // banner byte-identically.
            $suffix = '';
            if ($g->elapsedSeconds !== null) {
                $suffix .= ' · ' . self::formatTime($g->elapsedSeconds);
            }
            if ($b->isWon() && $g->newBest) {
                $suffix .= ' · ' . Lang::t('ui.new_best');
            }
            return SugarStyle::new()
                ->foreground(Color::hex($b->exploded ? '#ff5f87' : '#6ee7b7'))->bold()
                ->render(Lang::t($b->exploded ? 'ui.boom' : 'ui.cleared') . $suffix);
        }
        $remaining = max(0, $b->mineCount - $b->flagCount());
        $elapsed = $g->elapsed();
        $time = $elapsed !== null ? self::formatTime((int) $elapsed) : '0:00';
        return SugarStyle::new()->foreground(Color::hex('#a78bfa'))
            ->render(Lang::t('ui.status', [
                'mines'     => $b->mineCount,
                'flags'     => $b->flagCount(),
                'remaining' => $remaining,
                'time'      => $time,
            ]));
    }

    private static function formatTime(int $seconds): string
    {
        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;
        return sprintf('%d:%02d', $mins, $secs);
    }
}
