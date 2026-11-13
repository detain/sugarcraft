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
use SugarCraft\Flappy\Pipe;
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
        // flappy-31 (finding -034): every width arm runs over BOTH
        // postures — the plain renderer's returns now pass through fit()
        // like the colour path's, and the sweeps must be able to tell.
        foreach ([$wide, $crashed] as $g) {
            foreach ([null, ColorProfile::Ascii] as $profile) {
                $board = $profile === null ? $g : $g->update(new ColorProfileMsg($profile))[0];
                foreach (explode("\n", $board->view()) as $line) {
                    $this->assertLessThanOrEqual($board->frameCols, Width::of($line));
                }
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

    public function testQuarantineAnnouncementFoldsOnTheReadyBoard(): void
    {
        // flappy-18 (finding -018): a poisoned save moved aside at launch is
        // now NAMED on the ready board — the announcement obeys the
        // notice-sweep law (no line exceeds the frame; segments stay under
        // 38 cells) and is said once: the first flap retires it.
        $artifact = 'scores.json.corrupt-20261113-014500';
        $g = new Game(
            bird: $this->sized(62, 24)->bird,
            pipes: [],
            configDir: $this->hermeticConfigDir(),
            quarantinedSave: $artifact,
        );
        $view = $g->view();
        $this->assertStringContainsString($artifact, $view, 'the forensic copy must be named by basename');
        // flappy-31: the width sweep runs over both postures.
        [$plainG,] = $g->update(new ColorProfileMsg(ColorProfile::Ascii));
        foreach ([[0, $g], [1, $plainG]] as [$arm, $board]) {
            foreach (range(62, 82) as $cols) {
                [$sized,] = $board->update(new WindowSizeMsg($cols, 24));
                foreach (explode("\n", rtrim($sized->view(), "\n")) as $line) {
                    $this->assertLessThanOrEqual($cols, Width::of($line), "arm $arm ready-board line too wide at $cols: '$line'");
                }
            }
        }
        [$flapped,] = $g->update(new KeyMsg(KeyType::Space, ''));
        $this->assertStringNotContainsString('corrupt-', $flapped->view(), 'the announcement must not ride the live board');
        [$flappedPlain,] = $plainG->update(new KeyMsg(KeyType::Space, ''));
        $this->assertStringNotContainsString('corrupt-', $flappedPlain->view(), 'nor in the plain posture');
    }

    public function testOversizeStatusTextIsClampedNotSpilledInBothPostures(): void
    {
        // flappy-31 (finding -034): fit() routing IS the guard this row
        // installs on the plain renderer. A 120-cell single-word forensic
        // name cannot be folded below the frame by any budget math, so the
        // line exceeds the viewport unless fit() clamps it — plain without
        // the route fails exactly here while colour passes through its
        // long-standing clamp.
        $artifact = str_repeat('x', 120);
        $g = new Game(
            bird: $this->sized(62, 24)->bird,
            pipes: [],
            configDir: $this->hermeticConfigDir(),
            quarantinedSave: $artifact,
        );
        [$plain,] = $g->update(new ColorProfileMsg(ColorProfile::Ascii));
        foreach (['colour' => $g, 'plain' => $plain] as $posture => $board) {
            foreach ([62, 70, 81] as $cols) {
                [$sized,] = $board->update(new WindowSizeMsg($cols, 24));
                foreach (explode("\n", rtrim($sized->view(), "\n")) as $line) {
                    $this->assertLessThanOrEqual(
                        $cols,
                        Width::of($line),
                        "$posture posture spills past $cols cols: '$line'",
                    );
                }
            }
        }
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
        $g = new Game(
            bird: $this->sized(62, 24)->bird,
            pipes: [],
            score: 1234,
            crashed: true,
            highScores: [1234],
            newRecord: true,
            configDir: $this->hermeticConfigDir(),
        );
        // flappy-31: both postures sweep — the plain crash card draws the
        // plain_* tier family and (post -034 cure) shares fit()'s clamp.
        // flappy-78: tiers arrive as {why} templates; this board carries no
        // cause, so expand them exactly as the renderer's splat fallback.
        $expand = static function (string $key, string $why) use ($en): string {
            return str_replace('{why}', $en[$why], $en[$key]);
        };
        [$plain,] = $g->update(new ColorProfileMsg(ColorProfile::Ascii));
        $arms = [
            'colour' => [$g, [
                $expand('ui.crash_full', 'ui.why_splat'),
                $expand('ui.crash_short', 'ui.why_splat'),
                $en['ui.crash_floor'],
            ]],
            'plain' => [$plain, [
                $expand('ui.plain_crash_full', 'ui.plain_why_splat'),
                $expand('ui.plain_crash_short', 'ui.plain_why_splat'),
                $en['ui.plain_crash_floor'],
            ]],
        ];
        foreach ($arms as $posture => [$board, $tiers]) {
            foreach (range(62, 82) as $cols) {
                [$sized,] = $board->update(new WindowSizeMsg($cols, 24));
                $rows = explode("\n", rtrim($sized->view(), "\n"));
                $status = (string) end($rows);
                $this->assertLessThanOrEqual($cols, Width::of($status), "$posture status row exceeds $cols cols");
                $whole = false;
                foreach ($tiers as $tier) {
                    if (str_contains($status, $tier) === true) {
                        $whole = true;
                        break;
                    }
                }
                $this->assertTrue($whole, "no complete $posture crash hint survived at $cols cols: '$status'");
            }
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
        // flappy-36 rebalanced this budget: the shorter 'NEW BEST' noun and
        // the longer 'restart' verb nearly cancelled, leaving just enough
        // room for the floor tier at the old fixture — the best now carries
        // ten digits so the omit branch is genuinely exercised again.
        $g = new Game(
            bird: $this->sized(62, 24)->bird,
            pipes: [],
            score: 9999999,
            crashed: true,
            highScores: [9999999999],
            newRecord: true,
            configDir: $this->hermeticConfigDir(),
        );
        // flappy-31 dual arms, with an honest split: this fixture's budget
        // is posture-asymmetric — pickHint measures the ASSEMBLED prefix,
        // and the plain card's plain-worded prefix leaves room for the
        // 18-cell plain floor where the colour card's 16-cell floor falls
        // one cell short (the wider 'NEW BEST' word spacing). So colour
        // pins the OMIT branch, plain pins whole-survival — both share the
        // spill law and the no-clipped-rejoin law.
        [$plain,] = $g->update(new ColorProfileMsg(ColorProfile::Ascii));
        $en = require __DIR__ . '/../lang/en.php';
        [$coloredSized,] = $g->update(new WindowSizeMsg(62, 24));
        $colourRows = explode("\n", rtrim($coloredSized->view(), "\n"));
        $colourStatus = (string) end($colourRows);
        $this->assertLessThanOrEqual(62, Width::of($colourStatus));
        foreach (['ui.crash_full', 'ui.crash_short', 'ui.crash_floor'] as $key) {
            // flappy-78: expand the {why} templates as the splat fallback
            // would, so this pins the ACTUAL tier text at this budget.
            $tier = str_replace('{why}', $en['ui.why_splat'], $en[$key]);
            $this->assertStringNotContainsString($tier, $colourStatus, "colour tier $key cannot fit at this budget");
        }
        $this->assertStringNotContainsString('💥', $colourStatus, 'a too-wide hint must be omitted, never clipped');
        [$plainSized,] = $plain->update(new WindowSizeMsg(62, 24));
        $plainRows = explode("\n", rtrim($plainSized->view(), "\n"));
        $plainStatus = (string) end($plainRows);
        $this->assertLessThanOrEqual(62, Width::of($plainStatus), 'plain status spills past 62 cols');
        $plainWhole = false;
        foreach (['ui.plain_crash_full', 'ui.plain_crash_short', 'ui.plain_crash_floor'] as $key) {
            if (str_contains($plainStatus, str_replace('{why}', $en['ui.plain_why_splat'], $en[$key])) === true) {
                $plainWhole = true;
            }
        }
        $this->assertTrue($plainWhole, "plain posture must keep one complete crash hint whole: '$plainStatus'");
        // Either a COMPLETE tier rides the row or nothing of it does — the
        // old leak was a clipped remnant ('… q qui'), never a clean tier.
        // (An omitted row is safe too: short/full tiers END with the whole
        // floor text, so a partial floor prefix can only mean clipping.)
        if ($plainWhole === false) {
            $this->assertStringNotContainsString(
                substr($en['ui.plain_crash_floor'], 0, -2),
                $plainStatus,
                'a clipped hint remnant must not ride the plain row',
            );
        }
    }

    public function testPlainBoardWallsMapBlockedAndGapRows(): void
    {
        // flappy-86 integrity pin (surfaced by mutation probing): the plain
        // cell loop moved onto the wallRows precompute, yet NOTHING
        // asserted that a pipe column draws '#' on blocked rows and stays
        // open on gap rows in the Ascii posture — the goldens all run the
        // colour path, and an inverted blocked/gap lookup slipped through
        // the whole suite. This reddens that inversion (and any other drift
        // of the map, column by column, against the single source
        // collides()).
        $pipe = new Pipe(20, 9, 5);
        $g = new Game(
            bird: $this->sized(80, 24)->bird,
            pipes: [$pipe],
            configDir: $this->hermeticConfigDir(),
        );
        [$plain,] = $g->update(new ColorProfileMsg(ColorProfile::Ascii));
        $lines = explode("\n", $plain->view());
        for ($y = 0; $y < Game::HEIGHT; $y++) {
            $cell = substr((string) $lines[1 + $y], 21, 1);
            $this->assertSame(
                $pipe->collides(20, $y) === true ? '#' : ' ',
                $cell,
                "plain board row $y at the pipe column",
            );
        }
    }
}
