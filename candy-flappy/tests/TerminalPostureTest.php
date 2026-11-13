<?php

declare(strict_types=1);

namespace SugarCraft\Flappy\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Flappy\Game;
use SugarCraft\Flappy\Tests\Support\UsesHermeticConfig;

/**
 * Terminal posture (lib-reviews-1102 M6/M7/M8/M26/m30): resize is data the
 * model carries, an unrenderable terminal is refused instead of shredded
 * with escape codes, the ASCII tier draws plain glyphs, and no status row
 * may exceed the live viewport.
 */
final class TerminalPostureTest extends TestCase
{
    use UsesHermeticConfig;

    private function sized(int $cols, int $rows): Game
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        [$g,] = $g->update(new WindowSizeMsg($cols, $rows));
        return $g;
    }

    public function testWindowSizeMsgIsCarriedAsData(): void
    {
        $g = $this->sized(100, 40);
        $this->assertSame(100, $g->frameCols);
        $this->assertSame(40, $g->frameRows);
        // Exactly the minimum frame still renders the world, not the notice.
        $min = $this->sized(Game::MIN_COLS, Game::MIN_ROWS);
        $this->assertStringContainsString('score:', $min->view());
        $this->assertFalse($min->tooSmall());
    }

    public function testDefaultFrameBytesAreUntouched(): void
    {
        // Goldens run with a null profile and the default 62x21 seed; a
        // resize to exactly that must be byte-identical to no message at all.
        $plain = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        $sized = $this->sized(Game::MIN_COLS, Game::MIN_ROWS);
        $this->assertSame($plain->view(), $sized->view());
    }

    public function testTooSmallViewportGetsNoticeAndFreezesTheWorld(): void
    {
        // flappy-19: stand down is exercised on a LIVE board (the ready
        // card stands down on its own gate now, which would blur which
        // clause the null answers).
        [$g,] = $this->sized(80, 24)->update(new KeyMsg(KeyType::Space, ''));
        [$g,] = $g->update(new WindowSizeMsg(40, 10));
        $view = $g->view();
        $this->assertStringContainsString('needs a 62x21 terminal', $view);
        $this->assertStringContainsString('40x10', $view);
        $this->assertStringNotContainsString('score:', $view);
        // flappy-11: every notice line fits a 40-column terminal on its own
        // (first line <= 38 cells at the narrowest plausible window) and the
        // notice names the one key that works from it.
        $this->assertStringContainsString('press q to quit', $view);
        foreach (explode("\n", rtrim($view, "\n")) as $line) {
            $this->assertLessThanOrEqual(40, Width::of($line), 'notice line too wide: ' . $line);
        }
        // M6: nothing to see, nothing to simulate — the tick stands down.
        $this->assertNull($g->subscriptions());
    }

    public function testGrowingBackResumesTheCadence(): void
    {
        [$g,] = $this->sized(80, 24)->update(new KeyMsg(KeyType::Space, ''));
        [$g,] = $g->update(new WindowSizeMsg(40, 10));
        $this->assertNull($g->subscriptions(), 'the shrunken live board stands down first');
        [$g,] = $g->update(new WindowSizeMsg(80, 24));
        $this->assertSame(80, $g->frameCols);
        $subs = $g->subscriptions();
        $this->assertNotNull($subs);
        $ids = [];
        foreach ($subs->all() as $s) {
            $ids[] = $s->id;
        }
        $this->assertSame([Game::TICK_SUBSCRIPTION], $ids);
        $this->assertStringContainsString('score:', $g->view());
    }

    public function testNoTtyProfileRefusesToPlay(): void
    {
        // flappy-02 (finding -002): the refusal frame carries a quit Cmd —
        // this test used to BAKE the hang in (assert view + empty subs and
        // nothing else, i.e. a game only Ctrl+C ends).
        [$g, $cmd] = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir())
            ->update(new ColorProfileMsg(ColorProfile::NoTty));
        // flappy-16: the headline names the real requirement (a terminal
        // that can RENDER — a TERM=dumb user IS at an interactive one);
        // line1 folds at 38 cells so the pin tracks the intact first
        // fragment 'candy-flappy needs a terminal that can', and line2
        // names both disjoint detection causes whole.
        $this->assertStringContainsString('needs a terminal that can', $g->view());
        $this->assertStringContainsString('not an interactive terminal', $g->view());
        $this->assertStringNotContainsString("\x1b[", $g->view());
        // flappy-11: the refusal must survive a 40-column terminal without
        // the terminal's own invisible reflow, and it must name q.
        $this->assertStringContainsString('press q to quit', $g->view());
        foreach (explode("\n", rtrim($g->view(), "\n")) as $line) {
            $this->assertLessThanOrEqual(40, Width::of($line), 'refusal line too wide: ' . $line);
        }
        // flappy-19: the stand-down is allocation-free null now (the board
        // is both ready AND refused — either clause answers "arm nothing").
        $this->assertNull($g->subscriptions());
        $this->assertNotNull($cmd, 'NoTty must dispatch a quit, not hang until killed');
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testAsciiProfileDrawsPlainGlyphs(): void
    {
        $g = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        [$g,] = $g->update(new WindowSizeMsg(80, 24));
        [$g,] = $g->update(new ColorProfileMsg(ColorProfile::Ascii));
        $view = $g->view();
        $this->assertStringNotContainsString("\x1b[", $view);
        $this->assertStringContainsString('+------', $view);
        $this->assertStringNotContainsString('▓', $view);
        $this->assertStringNotContainsString('💥', $view);
        $this->assertStringContainsString('score:', $view);
    }

    public function testColourTiersRenderTheColourPath(): void
    {
        $control = Game::start(static fn(int $_max): int => 0, $this->hermeticConfigDir());
        foreach ([ColorProfile::Ansi, ColorProfile::Ansi256, ColorProfile::TrueColor] as $tier) {
            [$g,] = $control->update(new ColorProfileMsg($tier));
            // Byte-identical to the null-profile default: tiering only
            // changes posture for NoTty/Ascii; colour depth is delegated to
            // sugar-style's own degradation.
            $this->assertSame($control->view(), $g->view());
        }
    }

    public function testStatusLineNeverExceedsTheViewport(): void
    {
        $wide = new Game(
            bird: $this->sized(62, 24)->bird,
            pipes: [],
            score: 9999999,
            highScores: [987654],
            started: true,
            configDir: $this->hermeticConfigDir(),
        );
        $crashed = new Game(
            bird: $wide->bird,
            pipes: [],
            score: 9999999,
            crashed: true,
            highScores: [987654],
            newRecord: true,
            configDir: $this->hermeticConfigDir(),
        );
        foreach ([$wide, $crashed] as $g) {
            foreach (explode("\n", $g->view()) as $line) {
                $this->assertLessThanOrEqual($g->frameCols, Width::of($line));
            }
        }
        // The long hint survives on a comfortably wide frame:
        $roomy = new Game(
            bird: $wide->bird,
            pipes: [],
            score: 9999999,
            started: true,
            configDir: $this->hermeticConfigDir(),
            frameCols: 100,
        );
        $this->assertStringContainsString('space/⏎/↑/w flap', $roomy->view());
        $this->assertStringNotContainsString('space flap · q quit', $roomy->view());
    }

    public function testRestartCarriesViewportAndProfile(): void
    {
        $g = $this->sized(100, 40);
        [$g,] = $g->update(new ColorProfileMsg(ColorProfile::Ascii));
        $g = $g->tickN(80);
        $this->assertTrue($g->crashed);
        [$re, $cmd] = $g->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertNull($cmd);
        $this->assertSame(100, $re->frameCols);
        $this->assertSame(40, $re->frameRows);
        $this->assertSame(ColorProfile::Ascii, $re->colorProfile);
        // m30: it does NOT snap back to the 62x21 default.
        $this->assertFalse($re->tooSmall());
    }

    public function testRestartFromTooSmallStillShowsTheNotice(): void
    {
        $g = $this->sized(40, 10)->tickN(80);
        $this->assertTrue($g->crashed);
        [$re,] = $g->update(new KeyMsg(KeyType::Char, 'r'));
        $this->assertStringContainsString('needs a 62x21 terminal', $re->view());
    }

    public function testCrashRecordHintStaysWholeDownToTheFloor(): void
    {
        // flappy-14 (finding -021): with the 🏆 banner on the crash card the
        // assembled status row overran MIN_COLS=62 and fit() clipped the
        // short tier mid-word ('💥 splat — r retry · q q…'); at score 1234
        // the quit affordance disappeared. A floor tier (keys only, splat
        // noun dropped) must keep ONE complete crash hint whole across the
        // 62..82 band (82 = where fit() starts skipping the row walk).
        $en = require __DIR__ . '/../lang/en.php';
        $tiers = [$en['ui.crash_full'], $en['ui.crash_short'], $en['ui.crash_floor']];
        $g = new Game(
            bird: $this->sized(62, 24)->bird,
            pipes: [],
            score: 1234,
            crashed: true,
            highScores: [1234],
            newRecord: true,
            configDir: $this->hermeticConfigDir(),
        );
        foreach (range(62, 82) as $cols) {
            [$sized,] = $g->update(new WindowSizeMsg($cols, 24));
            $rows = explode("\n", rtrim($sized->view(), "\n"));
            $status = (string) end($rows);
            $this->assertLessThanOrEqual($cols, Width::of($status), "status row exceeds $cols cols");
            $whole = false;
            foreach ($tiers as $tier) {
                if (str_contains($status, $tier) === true) {
                    $whole = true;
                    break;
                }
            }
            $this->assertTrue($whole, "no complete crash hint survived at $cols cols: '$status'");
        }
    }

    public function testCrashStatusRowOmitsTheHintRatherThanClippingIt(): void
    {
        // flappy-46 (finding -046, the test-side twin of -021): the sweep in
        // the sibling test pins a COMPLETE tier surviving the 62..82 band;
        // this pins pickHint's third branch — when even the floor tier
        // cannot fit (a seven-digit score eating the budget), the hint is
        // OMITTED whole, never re-joined and re-clipped mid-word. The old
        // behaviour leaked exactly this: '💥 splat — r retry · q q'.
        $g = new Game(
            bird: $this->sized(62, 24)->bird,
            pipes: [],
            score: 9999999,
            crashed: true,
            highScores: [9999999],
            newRecord: true,
            configDir: $this->hermeticConfigDir(),
        );
        [$sized,] = $g->update(new WindowSizeMsg(62, 24));
        $rows = explode("\n", rtrim($sized->view(), "\n"));
        $status = (string) end($rows);
        $this->assertLessThanOrEqual(62, Width::of($status));
        $en = require __DIR__ . '/../lang/en.php';
        foreach (['ui.crash_full', 'ui.crash_short', 'ui.crash_floor'] as $key) {
            $this->assertStringNotContainsString($en[$key], $status, "tier $key cannot fit at this budget");
        }
        // No hint glyph bytes at all: a clipped re-join would leave 💥 in the row.
        $this->assertStringNotContainsString('💥', $status, 'a too-wide hint must be omitted, never clipped');
    }
}
