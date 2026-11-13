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
    /** Palette (M23: hoisted from the per-cell loop to named constants). */
    private const AMBER = '#fde68a';
    private const GREEN = '#6ee7b7';
    private const HINT = '#7d6e98';
    private const DOT = '#3a2c5a';
    private const CRASH = '#ff5f87';

    /**
     * M23: the per-cell loop rendered ~1080 cells per frame and each cell
     * rebuilt a Style/Color chain producing the SAME bytes every time. The
     * fully-styled glyph strings are process-static memos — one build per
     * glyph, ever. The goldens pin that the memo bytes equal the old
     * per-call bytes. flappy-32: the bird memo became a per-glyph map
     * (climb/level/fall), same law, keyed by the glyph.
     */
    private static array $birdCells = [];

    private static ?string $crashCell = null;
    private static ?string $pipeCell = null;
    private static ?string $dotCell = null;

    /**
     * flappy-85 (finding -077): the status-row and border chains were the
     * remaining per-frame Style builds (~4 fresh chains + Color::hex parses
     * per frame). Same law as the cell memos above — the STYLE objects are
     * immutable and shared; the rendered text still differs per frame, so
     * only the finished builder is memoized, never its output.
     */
    private static ?Style $frameStyle = null;
    private static ?Style $scoreStyle = null;
    private static ?Style $newBestStyle = null;
    private static ?Style $hintStyle = null;
    private static ?Style $crashStyle = null;

    private static function birdCell(string $glyph): string
    {
        return self::$birdCells[$glyph] ??= Style::new()->foreground(Color::hex(self::AMBER))->bold()->render($glyph);
    }

    /**
     * flappy-32 (finding -035): the bird points the way it is moving —
     * climbing '^', level '>', falling 'v'. The physics has computed the
     * vertical velocity every tick since M2; this is Tier-1 game feel
     * with zero new state, glyph from the velocity sign alone.
     */
    private static function pitchGlyph(float $velocityY): string
    {
        if ($velocityY < 0.0) {
            return '^';
        }
        if ($velocityY > 0.0) {
            return 'v';
        }
        return '>';
    }

    /**
     * The burst that lands on the bird's cell. m29 wanted a splat glyph on
     * the board; n9 picks the half-width ✕ over the 💥 the status line
     * announces because 💥 is East-Asian-width 2 — at the 62-column floor it
     * makes the board row one cell wider than the frame, so fit() truncates
     * it and the right border clips. The hint keeps the 💥; pickHint already
     * counts its two cells.
     */
    private static function crashCell(): string
    {
        return self::$crashCell ??= Style::new()->foreground(Color::hex(self::CRASH))->bold()->render('✕');
    }

    private static function pipeCell(): string
    {
        return self::$pipeCell ??= Style::new()->foreground(Color::hex(self::GREEN))->render('▓');
    }

    private static function dotCell(): string
    {
        return self::$dotCell ??= Style::new()->foreground(Color::hex(self::DOT))->render('·');
    }

    /**
     * flappy-85 (finding -077): the status/border chains rebuilt on every
     * frame — each is a fixed Style config around a dynamic string, and
     * Style is immutable, so the finished objects share process-wide like
     * the four cell memos above. Only `->render($text)` runs per frame.
     */
    private static function frameStyle(): Style
    {
        return self::$frameStyle ??= Style::new()
            ->border(Border::rounded())
            ->foreground(Color::hex(self::AMBER));
    }

    private static function scoreStyle(): Style
    {
        return self::$scoreStyle ??= Style::new()->bold()->foreground(Color::hex(self::AMBER));
    }

    private static function newBestStyle(): Style
    {
        return self::$newBestStyle ??= Style::new()->bold()->foreground(Color::hex(self::GREEN));
    }

    private static function hintStyle(): Style
    {
        return self::$hintStyle ??= Style::new()->foreground(Color::hex(self::HINT));
    }

    private static function crashStyle(): Style
    {
        return self::$crashStyle ??= Style::new()->foreground(Color::hex(self::CRASH))->bold();
    }

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

        // M23: one collides() pass per pipe per row (<= 6x18 checks) instead
        // of re-scanning every pipe inside every cell (60x18x<=6 before).
        $wallRows = [];
        foreach ($g->pipes as $p) {
            $blocked = [];
            for ($y = 0; $y < $h; $y++) {
                $blocked[$y] = $p->collides($p->x, $y);
            }
            $wallRows[$p->x] = $blocked;
        }

        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $cells = [];
            for ($x = 0; $x < $w; $x++) {
                $cells[] = self::cellGlyph($g, $x, $y, $bird->x, $birdRow, $wallRows);
            }
            $rows[] = implode('', $cells);
        }
        $field = implode("\n", $rows);

        $framed = self::frameStyle()->render($field);

        $scoreText = Lang::t('ui.score', ['score' => $g->score]);
        $score = self::scoreStyle()->render($scoreText);
        $highScore = $g->highScore();
        if ($g->crashed === true) {
            $highScoreLine = '';
            $highScorePlain = '';
            if ($g->newRecord && $g->score > 0) {
                $highScorePlain = Lang::t('ui.new_best', ['best' => $highScore]);
                $highScoreLine = self::newBestStyle()->render($highScorePlain) . '   ';
                $highScorePlain .= '   ';
            } elseif ($highScore > 0) {
                $highScorePlain = Lang::t('ui.best', ['best' => $highScore]);
                $highScoreLine = self::hintStyle()->render($highScorePlain) . '   ';
                $highScorePlain .= '   ';
            }
            // flappy-78 (finding -068): the hint names the cause of death
            // through a {why} placeholder in the crash templates; boards
            // that carry only the boolean (direct constructions, old
            // fixtures) keep the generic splat — byte-identical to the
            // pre-variant copy.
            $why = self::crashWhy($g, 'ui.why_ceiling', 'ui.why_ground', 'ui.why_pipe', 'ui.why_splat');
            $hintPlain = self::pickHint(
                $g,
                $scoreText . $highScorePlain,
                Lang::t('ui.crash_full', ['why' => $why]),
                Lang::t('ui.crash_short', ['why' => $why]),
                Lang::t('ui.crash_floor'),
            );
            $hint = self::crashStyle()->render($hintPlain);
            return self::fit($g, $framed . "\n " . $score . $highScoreLine . '    ' . $hint . "\n");
        }
        // m28: the live status shows the standing best, not just the
        // death screen — players want to know what they are chasing.
        $bestPlain = '';
        $bestLine = '';
        if ($highScore > 0) {
            $bestPlain = Lang::t('ui.best', ['best' => $highScore]);
            $bestLine = self::hintStyle()->render($bestPlain) . '    ';
        }
        // m6: the assembled live row is ' ' + score + bestLine + '    ' + hint,
        // and bestLine itself already trails four cells of padding — so the
        // budget's prefix must carry them, or pickHint under-counts by 4 and
        // fit() silently truncates the status row. Empty best contributes no
        // cells, matching the assembly exactly (no over-conservative collapse).
        $prefixCells = $bestPlain === '' ? $scoreText : $scoreText . $bestPlain . '    ';
        // M10: an unstarted board advertises the start key instead of the
        // flap cadence hint; the world is visibly frozen behind it.
        if ($g->started === false) {
            $hintPlain = self::pickHint(
                $g,
                $prefixCells,
                Lang::t('ui.ready_full'),
                Lang::t('ui.ready_short'),
            );
        } else {
            $hintPlain = self::pickHint(
                $g,
                $prefixCells,
                Lang::t('ui.live_full'),
                Lang::t('ui.live_short'),
            );
        }
        $hint = self::hintStyle()->render($hintPlain);

        // flappy-18 (finding -018): the launch that archived a poisoned
        // leaderboard says so once, on the ready board — a folded line
        // naming the forensic copy (notice-sweep discipline from flappy-11:
        // segments stay under 38 cells so nothing reflows at 40 columns).
        $warn = '';
        if ($g->started === false && $g->quarantinedSave !== null) {
            $warn = implode("\n ", self::foldNoticeLine(Lang::t('ui.quarantine', [
                'file' => $g->quarantinedSave,
            ]))) . "\n ";
        }

        return self::fit($g, $framed . "\n " . $warn . $score . $bestLine . '    ' . $hint . "\n");
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

        // flappy-86 (finding -078): mechanical port of M23's wallRows
        // precompute — one collides() pass per pipe per row instead of a
        // pipe rescan inside every cell (~4.3k checks/frame saved), the
        // shape renderColor already runs. Pure internal reshape: the bytes
        // per cell are decided exactly as before.
        $wallRows = [];
        foreach ($g->pipes as $p) {
            $blocked = [];
            for ($y = 0; $y < $h; $y++) {
                $blocked[$y] = $p->collides($p->x, $y);
            }
            $wallRows[$p->x] = $blocked;
        }

        $rows = [];
        $rows[] = '+' . str_repeat('-', $w) . '+';
        for ($y = 0; $y < $h; $y++) {
            $line = '|';
            for ($x = 0; $x < $w; $x++) {
                if ($x === $birdX && $y === $birdRow) {
                    $line .= $g->crashed === true ? 'x' : self::pitchGlyph($g->bird->body->velocity->y);
                    continue;
                }
                $cell = isset($wallRows[$x]) && $wallRows[$x][$y] === true ? '#' : ' ';
                if ($cell === ' ' && ($x + $g->tickIndex) % 12 === 0 && ($y % 5) === 2) {
                    $cell = '.';
                }
                $line .= $cell;
            }
            $rows[] = $line . '|';
        }
        $rows[] = '+' . str_repeat('-', $w) . '+';

        $scoreText = Lang::t('ui.score', ['score' => $g->score]);
        if ($g->crashed === true) {
            $highScore = $g->highScore();
            $recordPlain = '';
            if ($g->newRecord && $g->score > 0) {
                $recordPlain = Lang::t('ui.plain_new_best', ['best' => $highScore]) . '   ';
            } elseif ($highScore > 0) {
                $recordPlain = Lang::t('ui.best', ['best' => $highScore]) . '   ';
            }
            $why = self::crashWhy(
                $g,
                'ui.plain_why_ceiling',
                'ui.plain_why_ground',
                'ui.plain_why_pipe',
                'ui.plain_why_splat',
            );
            $hint = self::pickHint(
                $g,
                $scoreText . $recordPlain,
                Lang::t('ui.plain_crash_full', ['why' => $why]),
                Lang::t('ui.plain_crash_short', ['why' => $why]),
                Lang::t('ui.plain_crash_floor'),
            );
            return self::fit($g, implode("\n", $rows) . "\n " . $scoreText . $recordPlain . '    ' . $hint . "\n");
        }
        $bestPlain = '';
        if ($g->highScore() > 0) {
            $bestPlain = Lang::t('ui.best', ['best' => $g->highScore()]);
        }
        if ($g->started === false) {
            $hint = self::pickHint(
                $g,
                $scoreText . $bestPlain,
                Lang::t('ui.plain_ready_full'),
                Lang::t('ui.plain_ready_short'),
            );
        } else {
            $hint = self::pickHint(
                $g,
                $scoreText . $bestPlain,
                Lang::t('ui.plain_live_full'),
                Lang::t('ui.plain_live_short'),
            );
        }
        // flappy-18: the plain posture carries the same one-time quarantine
        // announcement (ASCII marker from the plain key family).
        $warn = '';
        if ($g->started === false && $g->quarantinedSave !== null) {
            $warn = implode("\n ", self::foldNoticeLine(Lang::t('ui.plain_quarantine', [
                'file' => $g->quarantinedSave,
            ]))) . "\n ";
        }
        // flappy-31 (finding -034): the plain posture now shares the colour
        // path's last line of defence — fit() clamps every row to the live
        // viewport. pickHint's budgeting is the reason nothing clips in
        // practice; fit() is the reason nothing SPILLS if the budgeting
        // ever drifts, and it must guard both renderers equally.
        return self::fit($g, implode("\n", $rows) . "\n " . $warn . $scoreText . $bestPlain . '    ' . $hint . "\n");
    }

    private static function refusal(): string
    {
        // flappy-11: the notices are the only text a user sees on a dead or
        // dumb terminal, and the terminal may itself be as narrow as 40
        // columns — fold every line so nothing wraps unpredictably, and end
        // with the single key that can leave the program (q or Ctrl-C).
        $lines = array_merge(
            self::foldNoticeLine(Lang::t('notty.line1')),
            self::foldNoticeLine(Lang::t('notty.line2')),
            [Lang::t('ui.quit_hint')],
        );
        return implode("\n", $lines) . "\n";
    }

    private static function tooSmallNotice(Game $g): string
    {
        $lines = array_merge(
            self::foldNoticeLine(Lang::t('size.notice', [
                'need' => Game::MIN_COLS . 'x' . Game::MIN_ROWS,
                'have' => "{$g->frameCols}x{$g->frameRows}",
            ])),
            self::foldNoticeLine(Lang::t('size.resume')),
            [Lang::t('ui.quit_hint')],
        );
        return implode("\n", $lines) . "\n";
    }

    /**
     * Word-wrap one notice line to at most 38 cells so it survives a 40
     * column terminal without the terminal's own (invisible, uncooperative)
     * reflow (flappy-11; tetris wraps its notice for the same reason).
     *
     * @return list<string>
     */
    private static function foldNoticeLine(string $text, int $width = 38): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $trial = $current === '' ? $word : $current . ' ' . $word;
            if ($current !== '' && Width::of($trial) > $width) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $trial;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    /**
     * Choose the hint that keeps ' ' + $prefixPlain + '    ' + hint inside the
     * frame. Contract (m6): $prefixPlain carries EVERY visible cell between the
     * leading space and the 4-space joiner — trailing padding on the score /
     * best segment included — so the budget equals the assembled row exactly.
     *
     * flappy-14 (finding -021): an optional third tier. With the 🏆 banner on
     * the crash card even the short hint overruns the 62-column floor and
     * fit() clipped it mid-word; the floor tier (keys only, splat noun
     * dropped) keeps a COMPLETE hint whole. When even that cannot fit the
     * hint is omitted whole rather than clipped — a missing affordance is
     * honest, a mid-word 'q qu' is the m6 defect class.
     */
    private static function pickHint(Game $g, string $prefixPlain, string $hintFull, string $hintShort, string $hintFloor = ''): string
    {
        $used = Width::of(' ' . $prefixPlain) + 4;
        if ($used + Width::of($hintFull) <= $g->frameCols) {
            return $hintFull;
        }
        if ($hintFloor === '' || $used + Width::of($hintShort) <= $g->frameCols) {
            return $hintShort;
        }
        return $used + Width::of($hintFloor) <= $g->frameCols ? $hintFloor : '';
    }

    /**
     * flappy-78 (finding -068): the cause noun slotting into the crash
     * templates' {why}. The lang keys arrive as literals from every call
     * site (fleet Lang-key law: grep sees the whole key), and the plain arm
     * gets the ui.plain_why_* set because cs/tr/pl transliterate their
     * plain tiers to ASCII. A null cause (boards that only assert the crash
     * boolean) keeps the historical generic splat.
     */
    private static function crashWhy(
        Game $g,
        string $kCeiling,
        string $kGround,
        string $kPipe,
        string $kSplat,
    ): string {
        return Lang::t(match ($g->crashCause) {
            Game::CRASH_CEILING => $kCeiling,
            Game::CRASH_GROUND => $kGround,
            Game::CRASH_PIPE => $kPipe,
            default => $kSplat,
        });
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

    /**
     * @param array<int, array<int, bool>> $wallRows precomputed open/closed
     *                                               rows keyed by pipe column
     */
    private static function cellGlyph(Game $g, int $x, int $y, int $birdX, int $birdRow, array $wallRows): string
    {
        // Bird glyph wins over everything (m29: on a crashed board the
        // bird cell renders the crash burst — a half-width ✕, see crashCell
        // for why the board cannot carry the hint's full-width 💥 at 62 cols).
        if ($x === $birdX && $y === $birdRow) {
            return $g->crashed === true ? self::crashCell() : self::birdCell(self::pitchGlyph($g->bird->body->velocity->y));
        }
        // Pipe walls; inside the gap it is just air.
        if (isset($wallRows[$x])) {
            return $wallRows[$x][$y] === true ? self::pipeCell() : ' ';
        }
        // Background: a sparse parallax dot field — every 12 columns (scroll-
        // shifted by the tick) and every 5th row starting at row 2 (m11 truth).
        if (($x + $g->tickIndex) % 12 === 0 && ($y % 5) === 2) {
            return self::dotCell();
        }
        return ' ';
    }
}
