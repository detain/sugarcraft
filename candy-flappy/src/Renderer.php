<?php

declare(strict_types=1);

namespace SugarCraft\Flappy;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Style\Border;
use SugarCraft\Style\Style;

/**
 * Pure view function: takes a Game and returns the framebuffer string.
 * Walks the playfield once, fills every cell, then frames it with a
 * rounded border.
 *
 * Posture dispatch (M6-M8, lib-reviews-1102): the coloured path is the
 * default and is byte-frozen by the fixtures/ goldens. A NoTty profile gets
 * a plain refusal (never escape codes into a dead pipe), a viewport under
 * Game::MIN_COLS x MIN_ROWS gets a notice screen instead of a corrupted
 * wrap, and the ASCII tier draws the same layout with plain glyphs. The
 * status line never exceeds the live frame width (M26): the long hint
 * collapses to a short one, and as a last resort the assembled row is
 * truncated to the viewport.
 */
final class Renderer
{
    public static function render(Game $g): string
    {
        if ($g->colorProfile === ColorProfile::NoTty) {
            return self::refusal();
        }
        if ($g->tooSmall()) {
            return self::tooSmallNotice($g);
        }
        if ($g->colorProfile === ColorProfile::Ascii) {
            return self::renderPlain($g);
        }
        return self::renderColor($g);
    }

    private static function renderColor(Game $g): string
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

        $scoreText = "score: {$g->score}";
        $score = Style::new()->bold()->foreground(Color::hex('#fde68a'))
            ->render($scoreText);
        $highScore = $g->highScore();
        if ($g->crashed === true) {
            $highScoreLine = '';
            $highScorePlain = '';
            if ($g->newRecord && $g->score > 0) {
                $highScorePlain = " 🏆 NEW HIGH SCORE: {$highScore}";
                $highScoreLine = Style::new()->bold()->foreground(Color::hex('#6ee7b7'))
                    ->render($highScorePlain) . '   ';
                $highScorePlain .= '   ';
            } elseif ($highScore > 0) {
                $highScorePlain = " best: {$highScore}";
                $highScoreLine = Style::new()->foreground(Color::hex('#7d6e98'))
                    ->render($highScorePlain) . '   ';
                $highScorePlain .= '   ';
            }
            $hintPlain = self::pickHint(
                $g,
                $scoreText . $highScorePlain,
                '💥 splat — press r to flap again, q to quit',
                '💥 splat — r retry · q quit',
            );
            $hint = Style::new()->foreground(Color::hex('#ff5f87'))->bold()
                ->render($hintPlain);
            return self::fit($g, $framed . "\n " . $score . $highScoreLine . '    ' . $hint . "\n");
        }
        $hintPlain = self::pickHint(
            $g,
            $scoreText,
            'space / ↑ / w  flap   ·   q  quit',
            'space flap · q quit',
        );
        $hint = Style::new()->foreground(Color::hex('#7d6e98'))
            ->render($hintPlain);

        return self::fit($g, $framed . "\n " . $score . '    ' . $hint . "\n");
    }

    /**
     * Plain-glyph posture for ColorProfile::Ascii: same layout, no SGR, no
     * non-ASCII characters (M8). Goldens never hit this path — they run
     * with a null profile (the default), which keeps the colour bytes.
     */
    private static function renderPlain(Game $g): string
    {
        $w = Game::WIDTH;
        $h = Game::HEIGHT;
        $birdX = $g->bird->x;
        $birdRow = $g->bird->row();

        $rows = [];
        $rows[] = '+' . str_repeat('-', $w) . '+';
        for ($y = 0; $y < $h; $y++) {
            $line = '|';
            for ($x = 0; $x < $w; $x++) {
                if ($x === $birdX && $y === $birdRow) {
                    $line .= '>';
                    continue;
                }
                $cell = ' ';
                foreach ($g->pipes as $p) {
                    if ($p->x !== $x) {
                        continue;
                    }
                    $cell = $p->collides($x, $y) === true ? '#' : ' ';
                    break;
                }
                if ($cell === ' ' && ($x + $g->tickIndex) % 12 === 0 && ($y % 5) === 2) {
                    $cell = '.';
                }
                $line .= $cell;
            }
            $rows[] = $line . '|';
        }
        $rows[] = '+' . str_repeat('-', $w) . '+';

        $scoreText = "score: {$g->score}";
        if ($g->crashed === true) {
            $highScore = $g->highScore();
            $recordPlain = '';
            if ($g->newRecord && $g->score > 0) {
                $recordPlain = " NEW HIGH SCORE: {$highScore}   ";
            } elseif ($highScore > 0) {
                $recordPlain = " best: {$highScore}   ";
            }
            $hint = self::pickHint(
                $g,
                $scoreText . $recordPlain,
                'x splat - press r to flap again, q to quit',
                'x splat - r retry - q quit',
            );
            return implode("\n", $rows) . "\n " . $scoreText . $recordPlain . '    ' . $hint . "\n";
        }
        $hint = self::pickHint(
            $g,
            $scoreText,
            'space / ^ / w  flap   .   q  quit',
            'space flap . q quit',
        );
        return implode("\n", $rows) . "\n " . $scoreText . '    ' . $hint . "\n";
    }

    private static function refusal(): string
    {
        return "candy-flappy needs an interactive terminal.\n"
            . "No usable colour capability was detected (non-TTY stdout or TERM=dumb).\n";
    }

    private static function tooSmallNotice(Game $g): string
    {
        return "candy-flappy needs a " . Game::MIN_COLS . "x" . Game::MIN_ROWS . " terminal;"
            . " this one is {$g->frameCols}x{$g->frameRows}.\n"
            . "Resize the window and the game resumes right here.\n";
    }

    /** Choose the hint that keeps ' ' + $prefixPlain + '    ' + hint inside the frame. */
    private static function pickHint(Game $g, string $prefixPlain, string $hintFull, string $hintShort): string
    {
        if (Width::of(' ' . $prefixPlain) + 4 + Width::of($hintFull) <= $g->frameCols) {
            return $hintFull;
        }
        return $hintShort;
    }

    /** Last-resort guarantee (M26): no rendered row exceeds the viewport. */
    private static function fit(Game $g, string $frame): string
    {
        if ($g->frameCols >= Game::MIN_COLS + 20) {
            // Comfortably wide: every hint already fits; skip the per-row walk.
            return $frame;
        }
        $out = [];
        foreach (explode("\n", $frame) as $line) {
            $out[] = Width::of($line) <= $g->frameCols ? $line : Width::truncateAnsi($line, $g->frameCols);
        }
        return implode("\n", $out);
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
            if ($p->collides($x, $y) === true) {
                return Style::new()->foreground(Color::hex('#6ee7b7'))->render('▓');
            }
            // Inside the gap: just air.
            return ' ';
        }
        // Background: a sparse parallax dot field — every 12 columns (scroll-
        // shifted by the tick) and every 5th row starting at row 2 (m11 truth).
        if (($x + $g->tickIndex) % 12 === 0 && ($y % 5) === 2) {
            return Style::new()->foreground(Color::hex('#3a2c5a'))->render('·');
        }
        return ' ';
    }
}
