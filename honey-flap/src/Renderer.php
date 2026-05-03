<?php

declare(strict_types=1);

namespace CandyCore\Flap;

use CandyCore\Core\Util\Color;
use CandyCore\Sprinkles\Border;
use CandyCore\Sprinkles\Style;

/**
 * Pure view function: takes a Game and returns the framebuffer string.
 * Walks the playfield once, fills every cell, then frames it with a
 * rounded border.
 */
final class Renderer
{
    public static function render(Game $g): string
    {
        $w = Game::WIDTH;
        $h = Game::HEIGHT;
        $bird = $g->bird;
        $birdRow = $bird->row();

        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $cells = [];
            for ($x = 0; $x < $w; $x++) {
                $cells[] = self::cellGlyph($g, $x, $y, $bird->x, $birdRow);
            }
            $rows[] = implode('', $cells);
        }
        $field = implode("\n", $rows);

        $framed = Style::new()
            ->border(Border::rounded())
            ->foreground(Color::hex('#fde68a'))
            ->render($field);

        $score = Style::new()->bold()->foreground(Color::hex('#fde68a'))
            ->render("score: {$g->score}");
        if ($g->crashed) {
            $hint = Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render('💥 splat — press r to flap again, q to quit');
        } else {
            $hint = Style::new()->foreground(Color::hex('#7d6e98'))
                ->render('space / ↑ / w  flap   ·   q  quit');
        }

        return $framed . "\n " . $score . "    " . $hint . "\n";
    }

    private static function cellGlyph(Game $g, int $x, int $y, int $birdX, int $birdRow): string
    {
        // Bird glyph wins over everything.
        if ($x === $birdX && $y === $birdRow) {
            return Style::new()->foreground(Color::hex('#fde68a'))->bold()->render('>');
        }
        // Pipe walls.
        foreach ($g->pipes as $p) {
            if ($p->x !== $x) continue;
            if ($p->collides($x, $y)) {
                return Style::new()->foreground(Color::hex('#6ee7b7'))->render('▓');
            }
            // Inside the gap: just air.
            return ' ';
        }
        // Background — leading column gets a subtle dot every 4 rows for parallax.
        if (($x + $g->tickIndex) % 12 === 0 && ($y % 5) === 2) {
            return Style::new()->foreground(Color::hex('#3a2c5a'))->render('·');
        }
        return ' ';
    }
}
