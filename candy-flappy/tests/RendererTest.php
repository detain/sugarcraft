<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Util\Width;
use SugarCraft\Flappy\Game;
use SugarCraft\Flappy\Renderer;
use PHPUnit\Framework\TestCase;

use SugarCraft\Flappy\Tests\Support\UsesHermeticConfig;

final class RendererTest extends TestCase
{
    use UsesHermeticConfig;

    private function game(): Game
    {
        // Deterministic PRNG: always return 0 → pipes pin to top.
        // M10: flap once so the board is LIVE, not on the ready card.
        [$g, ] = Game::start(static fn(int $max): int => 0, $this->hermeticConfigDir())
            ->update(new KeyMsg(KeyType::Space));
        return $g;
    }

    public function testRenderHasBirdGlyph(): void
    {
        $out = Renderer::render($this->game());
        $this->assertStringContainsString('>', $out);
    }

    public function testRenderShowsScore(): void
    {
        $out = Renderer::render($this->game());
        $this->assertStringContainsString('score:', $out);
    }

    public function testRenderShowsHelpHintWhenAlive(): void
    {
        $out = Renderer::render($this->game());
        $this->assertStringContainsString('flap', $out);
        $this->assertStringContainsString('quit', $out);
        $this->assertStringNotContainsString('splat', $out);
    }

    public function testRenderShowsCrashHint(): void
    {
        $g = new Game(
            bird:  $this->game()->bird,
            pipes: [],
            score: 0,
            crashed: true,
            highScores: [],
        );
        $out = Renderer::render($g);
        $this->assertStringContainsString('splat', $out);
        $this->assertStringContainsString('press r', $out);
    }

    public function testRenderHasNonZeroDimensions(): void
    {
        $out = Renderer::render($this->game());
        $lines = explode("\n", $out);
        $this->assertGreaterThan(Game::HEIGHT, count($lines));
    }

    public function testRenderShowsNewHighScoreBanner(): void
    {
        // Build a crashed Game where the score IS a strict new record.
        // withHighScore(99) on an empty list creates newRecord=true.
        $base = new Game(
            bird:  $this->game()->bird,
            pipes: [],
            score: 0,
            crashed: false,
            highScores: [],
        );
        $crashed = $base->tickN(80);  // crash into floor
        $withScore = new Game(
            bird: $crashed->bird,
            pipes: $crashed->pipes,
            score: 99,
            crashed: true,
            tickIndex: $crashed->tickIndex,
            highScores: [99],
            newRecord: true,
        );
        $out = Renderer::render($withScore);
        $this->assertStringContainsString('NEW BEST', $out);
        $this->assertStringContainsString('🏆', $out);
    }

    public function testRenderShowsBestLineWhenNotARecord(): void
    {
        // Crashed with score below existing best — shows "best:" not "NEW BEST".
        $g = new Game(
            bird:  $this->game()->bird,
            pipes: [],
            score: 3,
            crashed: true,
            highScores: [10, 20],  // best is 20
            newRecord: false,
        );
        $out = Renderer::render($g);
        $this->assertStringContainsString('best:', $out);
        $this->assertStringNotContainsString('NEW BEST', $out);
    }

    public function testRenderTieDoesNotShowNewRecord(): void
    {
        // Tie (score equals best but doesn't beat it) — newRecord must be false.
        // The score=10, highScores=[10] means newRecord=false (not strictly greater).
        $g = new Game(
            bird:  $this->game()->bird,
            pipes: [],
            score: 10,
            crashed: true,
            highScores: [10],  // score equals best, not greater
            newRecord: false,
        );
        $out = Renderer::render($g);
        $this->assertStringNotContainsString('NEW BEST', $out);
        $this->assertStringContainsString('best:', $out);
    }

    /**
     * m6: pickHint's budget must equal the assembled status row. The old live
     * path under-counted by the four cells bestLine trails, so the full hint
     * was chosen for rows that only fit after fit() truncated them — the
     * giveaway is a status row containing NEITHER complete hint. Sweeps both
     * live postures across the band where budget and truth used to diverge.
     */
    public function testTheLiveStatusHintIsNeverMidRowTruncated(): void
    {
        $cases = [
            [true, 'space/⏎/↑/w flap · r restart · q quit', 'space flap · q quit'],
            [false, 'press space/⏎/↑/w to start · q quit', 'space to start · q quit'],
        ];
        foreach ($cases as [$started, $full, $short]) {
            for ($cols = Game::MIN_COLS; $cols <= Game::MIN_COLS + 19; $cols++) {
                $g = new Game(
                    bird: $this->game()->bird,
                    pipes: [],
                    score: 12345,
                    crashed: false,
                    started: $started,
                    highScores: [678],
                    frameCols: $cols,
                );
                $rows = explode("\n", Renderer::render($g));
                $status = $rows[count($rows) - 2];
                $this->assertLessThanOrEqual($cols, Width::of($status), "live status overran {$cols} cols (started=" . var_export($started, true) . ')');
                $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $status);
                $this->assertTrue(
                    str_contains($plain, $full) || str_contains($plain, $short),
                    "live hint truncated mid-word at {$cols} cols (started=" . var_export($started, true) . ')',
                );
            }
        }
    }

    /**
     * m6 companion: the crash path's budget already carried its three-cell
     * tail and matched the row exactly. fit() is SKIPPED from MIN_COLS+20 up,
     * so this band has no truncation safety net — pin the exact fit at the
     * width where the record banner's row (measured 83 cells) just clears it.
     */
    public function testTheCrashRecordRowFitsWhereFitIsSkipped(): void
    {
        for ($cols = 83; $cols <= 86; $cols++) {
            $g = new Game(
                bird: $this->game()->bird,
                pipes: [],
                score: 500,
                crashed: true,
                highScores: [500],
                newRecord: true,
                frameCols: $cols,
            );
            $rows = explode("\n", Renderer::render($g));
            $status = $rows[count($rows) - 2];
            $this->assertLessThanOrEqual($cols, Width::of($status), "crash record row overran {$cols} cols");
            $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $status);
            $this->assertStringContainsString('💥 splat — press r to restart · q to quit', $plain);
        }
    }

    /**
     * n9: at the 62-column floor the board row carrying the burst glyph must
     * keep its right border. The old full-width 💥 made that row 63 cells, so
     * fit() truncated it and the border was clipped away one column short.
     * Pin: every row of a crashed floor frame stays within budget, and the
     * burst's own row still ends on the │ border after ANSI is stripped.
     */
    public function testTheCrashBurstLeavesTheFloorFrameBorderIntact(): void
    {
        $g = new Game(
            bird: $this->game()->bird,
            pipes: [],
            score: 3,
            crashed: true,
            highScores: [42],
            frameCols: Game::MIN_COLS,
        );
        $rows = explode("\n", Renderer::render($g));
        $burstRows = 0;
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(Game::MIN_COLS, Width::of($row));
            $plain = (string) preg_replace('/\e\[[0-9;]*m/', '', $row);
            if (str_contains($plain, '✕')) {
                $burstRows++;
                $this->assertStringEndsWith('│', $plain, 'burst row lost its right border at the floor width');
            }
        }
        $this->assertSame(1, $burstRows, 'the crash burst is on the board exactly once');
    }

    public function testTheCellLoopCarriesNoStyleConstruction(): void
    {
        // M23 structural pin: the per-cell glyph path must draw from the
        // memoised styled strings — a reintroduced Style::new() inside
        // cellGlyph is the regression this catches.
        $src = (string) file_get_contents(__DIR__ . '/../src/Renderer.php');
        $start = strpos($src, 'private static function cellGlyph(');
        $this->assertIsInt($start);
        $body = substr($src, $start);
        $this->assertStringNotContainsString('Style::new()', $body);
        $this->assertStringNotContainsString('Color::hex(', $body);
    }
}
