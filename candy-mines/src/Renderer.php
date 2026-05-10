<?php

declare(strict_types=1);

namespace SugarCraft\Mines;

use SugarCraft\Core\Util\Color;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;

/**
 * Pure view function: takes a {@see Game} and returns the framebuffer
 * string. No I/O, no caching — every call rebuilds from scratch so
 * snapshot tests can assert against the output directly.
 */
final class Renderer
{
    private const ADJ_COLOURS = [
        1 => '#7dd3fc',  // sky
        2 => '#6ee7b7',  // mint
        3 => '#fde68a',  // lemon
        4 => '#a78bfa',  // grape
        5 => '#ff8caa',  // pink
        6 => '#fcd5b4',  // peach
        7 => '#fb923c',  // orange
        8 => '#ff5f87',  // hot pink
    ];

    public static function render(Game $g): string
    {
        $b = $g->board;
        $rows = [];
        for ($y = 0; $y < $b->height; $y++) {
            $cells = [];
            for ($x = 0; $x < $b->width; $x++) {
                $cells[] = self::cellGlyph($b, $x, $y, $g->cursorX, $g->cursorY);
            }
            $rows[] = implode(' ', $cells);
        }
        $grid = implode("\n", $rows);

        $framed = Style::new()
            ->border(Border::rounded())
            ->padding(0, 1)
            ->render($grid);

        $status = self::status($b, $g->elapsed());
        $help   = "↑ ↓ ← →  move  ·  space  reveal  ·  f  flag  ·  r  restart  ·  q  quit";
        return $framed . "\n " . $status . "\n " .
               Style::new()->foreground(Color::hex('#7d6e98'))->render($help) . "\n";
    }

    private static function cellGlyph(Board $b, int $x, int $y, int $cx, int $cy): string
    {
        $cell = $b->cell($x, $y);
        if ($cell === null) return ' ';
        $glyph = match (true) {
            $cell->flagged => 'F',
            !$cell->revealed && ($b->exploded || $b->isWon()) && $cell->mine => '*',
            !$cell->revealed => '·',
            $cell->mine     => '*',
            $cell->adjacent === 0 => ' ',
            default => (string) $cell->adjacent,
        };
        // Colour map.
        $style = Style::new();
        if ($cell->revealed && $cell->mine) {
            $style = $style->foreground(Color::hex('#ff5f87'))->bold();
        } elseif ($cell->flagged) {
            $style = $style->foreground(Color::hex('#fde68a'))->bold();
        } elseif ($cell->revealed && $cell->adjacent > 0) {
            $style = $style->foreground(Color::hex(self::ADJ_COLOURS[$cell->adjacent] ?? '#ffffff'));
        } elseif (!$cell->revealed) {
            $style = $style->foreground(Color::hex('#5a4a78'));
        }
        // Cursor highlight: invert the glyph background.
        if ($x === $cx && $y === $cy) {
            $style = $style->reverse();
        }
        return $style->render($glyph);
    }

    private static function status(Board $b, ?int $elapsed): string
    {
        if ($b->exploded) {
            return Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render('💥 boom — press r to restart');
        }
        if ($b->isWon()) {
            return Style::new()->foreground(Color::hex('#6ee7b7'))->bold()
                ->render('★ cleared — press r to play again');
        }
        $remaining = max(0, $b->mineCount - $b->flagCount());
        $time = $elapsed !== null ? self::formatTime($elapsed) : '0:00';
        return Style::new()->foreground(Color::hex('#a78bfa'))
            ->render("mines: {$b->mineCount}  ·  flags: {$b->flagCount()}  ·  remaining: {$remaining}  ·  time: {$time}");
    }

    private static function formatTime(int $seconds): string
    {
        $mins = intdiv($seconds, 60);
        $secs = $seconds % 60;
        return sprintf('%d:%02d', $mins, $secs);
    }
}
