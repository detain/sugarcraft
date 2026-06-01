<?php

declare(strict_types=1);

namespace SugarCraft\Mines;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Style;
use SugarCraft\Core\Util\Color;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style as SprinklesStyle;

/**
 * Pure view function: takes a {@see Game} and returns the framebuffer
 * string. No I/O, no caching — every call rebuilds from scratch so
 * snapshot tests can assert against the output directly.
 *
 * Rendering uses a {@see Buffer} for the minefield: each cell is
 * zone-tagged via {@see Mark::wrap()} using "cell:$row:$col" so
 * {@see Scanner::hit()} can resolve mouse coordinates to cell
 * positions on click/flag events.
 *
 * Mirrors charmbracelet/bubbletea — Minesweeper renderer.
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

    public static function render(Game $g): string
    {
        $b = $g->board;
        $boardBuf = Buffer::new($b->width, $b->height);

        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $boardBuf = $boardBuf->withCellAt($x, $y, self::cellBufferCell($b, $x, $y, $g->cursorX, $g->cursorY));
            }
        }

        $interior = $boardBuf->toAnsi();

        $framed = SprinklesStyle::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($interior);

        $status = self::status($b, $g->elapsed());
        $help   = "↑ ↓ ← →  move  ·  space  reveal  ·  f  flag  ·  r  restart  ·  q  quit";
        return $framed . "\n " . $status . "\n " .
               SprinklesStyle::new()->foreground(Color::hex('#7d6e98'))->render($help) . "\n";
    }

    /**
     * Build a Buffer cell for the minefield at (col, row).
     *
     * Each cell is zone-tagged with Mark::wrap("cell:$row:$col", $glyph)
     * so Scanner::hit() can map mouse coordinates back to a cell.
     */
    private static function cellBufferCell(Board $b, int $col, int $row, int $cx, int $cy): \SugarCraft\Buffer\Cell
    {
        $cell = $b->cell($col, $row);
        if ($cell === null) {
            return \SugarCraft\Buffer\Cell::new(' ');
        }

        [$glyph, $fg, $bg, $attrs] = self::cellStyleData($cell, $b, $col, $row, $cx, $cy);

        // Zone-tag the glyph for mouse hit detection.
        $marked = Mark::zone("cell:{$row}:{$col}", $glyph);
        $style = Style::new($fg, $bg, $attrs);

        return \SugarCraft\Buffer\Cell::new($marked, $style);
    }

    /**
     * @return array{0:string, 1:?int, 2:?int, 3:int} glyph, fg, bg, attrs
     */
    private static function cellStyleData(Cell $cell, Board $b, int $col, int $row, int $cx, int $cy): array
    {
        $glyph = match (true) {
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
        } elseif ($cell->flagged) {
            $fg = 0xfde68a;
            $attrs = Style::ATTR_BOLD;
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
     * Render just the minefield as ANSI with Mark zones, and also
     * return a ready-to-use Scanner so callers can hit-test clicks.
     *
     * @return array{0:string, 1:Scanner}
     */
    public static function renderWithScanner(Game $g): array
    {
        $b = $g->board;
        $boardBuf = Buffer::new($b->width, $b->height);

        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $boardBuf = $boardBuf->withCellAt($x, $y, self::cellBufferCell($b, $x, $y, $g->cursorX, $g->cursorY));
            }
        }

        $interior = $boardBuf->toAnsi();
        $scanner = Scanner::new()->scan($interior);

        $framed = SprinklesStyle::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($interior);

        $status = self::status($b, $g->elapsed());
        $help   = "↑ ↓ ← →  move  ·  space  reveal  ·  f  flag  ·  r  restart  ·  q  quit";
        $full = $framed . "\n " . $status . "\n " .
                SprinklesStyle::new()->foreground(Color::hex('#7d6e98'))->render($help) . "\n";

        return [$full, $scanner];
    }

    /**
     * Resolve a mouse click at (col, row) to a cell coordinate.
     *
     * Uses Scanner to parse Mark zones from the rendered minefield.
     * Returns null if the click was outside any cell zone.
     *
     * @return array{0:int,1:int}|null col, row of the hit cell
     */
    public static function resolveClick(Game $g, int $col, int $row): ?array
    {
        $b = $g->board;
        $boardBuf = Buffer::new($b->width, $b->height);

        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $boardBuf = $boardBuf->withCellAt($x, $y, self::cellBufferCell($b, $x, $y, $g->cursorX, $g->cursorY));
            }
        }

        $interior = $boardBuf->toAnsi();
        $scanner = Scanner::new()->scan($interior);
        $zone = $scanner->hit($col, $row);

        if ($zone === null) {
            return null;
        }

        // Zone id format: "cell:$row:$col".
        if (preg_match('/^cell:(\d+):(\d+)$/', $zone->id, $m)) {
            return [(int) $m[2], (int) $m[1]];  // col, row
        }

        return null;
    }

    private static function status(Board $b, ?float $elapsed): string
    {
        if ($b->exploded) {
            return SprinklesStyle::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render('💥 boom — press r to restart');
        }
        if ($b->isWon()) {
            return SprinklesStyle::new()->foreground(Color::hex('#6ee7b7'))->bold()
                ->render('★ cleared — press r to play again');
        }
        $remaining = max(0, $b->mineCount - $b->flagCount());
        $time = $elapsed !== null ? self::formatTime((int) $elapsed) : '0:00';
        return SprinklesStyle::new()->foreground(Color::hex('#a78bfa'))
            ->render("mines: {$b->mineCount}  ·  flags: {$b->flagCount()}  ·  remaining: {$remaining}  ·  time: {$time}");
    }

    private static function formatTime(int $seconds): string
    {
        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;
        return sprintf('%d:%02d', $mins, $secs);
    }
}
