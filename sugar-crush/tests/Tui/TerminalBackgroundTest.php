<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Crush\Tui\TerminalBackground;

/**
 * crush_code.md Phase 8 item 5: nothing in sugar-crush probed the terminal's
 * background, so a user on a light terminal had to know to run `/theme light`.
 * These pin the detection rules and, more importantly, the precedence: the
 * terminal's own OSC 11 answer always beats the environment's guess.
 */
final class TerminalBackgroundTest extends TestCase
{
    protected function setUp(): void
    {
        TerminalBackground::forget();
    }

    protected function tearDown(): void
    {
        TerminalBackground::forget();
    }

    public function testAnAbsentOrUnparseableColorfgbgFallsBackToDark(): void
    {
        $this->assertTrue(TerminalBackground::detect([]));
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => '']));
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => 'default;default']));
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => 'nonsense']));
    }

    /**
     * The two system white slots are light and bright-black is dark - the pair
     * SprinklesTheme::adaptive()'s `>= 8` rule gets backwards, which is why
     * this class exists rather than delegating to it. (7 is #e5e5e5, 15 is
     * #ffffff, 8 is #7f7f7f at luminance 0.21.)
     */
    public function testTheSystemWhiteSlotsAreLightAndBrightBlackIsNot(): void
    {
        $this->assertFalse(TerminalBackground::detect(['COLORFGBG' => '0;15']));
        $this->assertFalse(TerminalBackground::detect(['COLORFGBG' => '0;7']));
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => '15;0']));
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => '15;8']));
    }

    /**
     * The background field is an xterm-256 index, not just a system colour, so
     * it is resolved to RGB and put through the same luminance test the OSC 11
     * path uses. An allow-list of 7 and 15 read every 256-colour light terminal
     * as dark - `COLORFGBG=0;255` is the top of the greyscale ramp (#eeeeee).
     */
    public function testA256ColourLightBackgroundIsRecognised(): void
    {
        $this->assertFalse(TerminalBackground::detect(['COLORFGBG' => '0;255']), '255 = #eeeeee');
        $this->assertFalse(TerminalBackground::detect(['COLORFGBG' => '0;231']), '231 = cube white');
        $this->assertFalse(TerminalBackground::detect(['COLORFGBG' => '0;254']));
        // ...and the dark end of the same ramp still reads dark.
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => '15;232']), '232 = #080808');
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => '15;52']), '52 = dark maroon');
    }

    /**
     * Color::ansi256() throws outside 0-255, and detect() is reached from theme
     * resolution inside view() - a throw there would take the Program down with
     * the terminal still in raw mode. Range-check, don't catch.
     */
    public function testAnOutOfRangePaletteIndexFallsBackToDarkInsteadOfThrowing(): void
    {
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => '0;256']));
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => '0;999999999999']));
    }

    /** rxvt emits a three-field `fg;faint;bg` form - the background is last. */
    public function testThreeFieldColorfgbgReadsTheLastFieldAsTheBackground(): void
    {
        $this->assertFalse(TerminalBackground::detect(['COLORFGBG' => '0;default;15']));
        $this->assertTrue(TerminalBackground::detect(['COLORFGBG' => '15;default;0']));
    }

    public function testTheEnvOverrideBeatsColorfgbg(): void
    {
        $this->assertTrue(TerminalBackground::detect([
            TerminalBackground::ENV_OVERRIDE => 'dark',
            'COLORFGBG' => '0;15',
        ]));
        $this->assertFalse(TerminalBackground::detect([
            TerminalBackground::ENV_OVERRIDE => ' LIGHT ',
            'COLORFGBG' => '15;0',
        ]));
        // An unrecognised value is ignored rather than treated as either.
        $this->assertFalse(TerminalBackground::detect([
            TerminalBackground::ENV_OVERRIDE => 'purple',
            'COLORFGBG' => '0;15',
        ]));
    }

    /**
     * The point of the whole class: once the terminal itself has answered over
     * OSC 11, that real luminance measurement replaces every heuristic.
     */
    public function testAnObservedOsc11ReplyOverridesTheEnvironmentGuess(): void
    {
        $env = ['COLORFGBG' => '15;0']; // environment says dark

        $this->assertTrue(TerminalBackground::isDark($env));
        $this->assertNull(TerminalBackground::observed());

        TerminalBackground::observe(new BackgroundColorMsg(255, 255, 255));

        $this->assertFalse(TerminalBackground::observed());
        $this->assertFalse(TerminalBackground::isDark($env));

        TerminalBackground::observe(new BackgroundColorMsg(0, 0, 0));

        $this->assertTrue(TerminalBackground::isDark($env));
    }

    /**
     * ...but an explicit `SUGARCRUSH_BACKGROUND` outranks even that. The
     * ordering only became observable once `observe()` was wired into the live
     * shell (crush_code.md Phase 8 item 6): with the query now sent on every
     * launch, ranking the measurement above the override would leave the
     * documented escape hatch with nothing to escape on any terminal that
     * answers OSC 11 — which is most of them.
     */
    public function testTheEnvOverrideBeatsEvenAnObservedOsc11Reply(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(0, 0, 0)); // terminal says dark

        $this->assertTrue(TerminalBackground::isDark([]), 'no override: the terminal wins');
        $this->assertFalse(
            TerminalBackground::isDark([TerminalBackground::ENV_OVERRIDE => 'light']),
            'the user said light; the measurement does not get to argue',
        );

        TerminalBackground::observe(new BackgroundColorMsg(255, 255, 255)); // terminal says light

        $this->assertFalse(TerminalBackground::isDark([]));
        $this->assertTrue(TerminalBackground::isDark([TerminalBackground::ENV_OVERRIDE => ' DARK ']));

        // An unrecognised override is still ignored rather than pinning a
        // palette on a typo - it falls back through to the observed answer.
        $this->assertFalse(TerminalBackground::isDark([
            TerminalBackground::ENV_OVERRIDE => 'purple',
            'COLORFGBG' => '15;0',
        ]), 'typo falls through to the terminal, not to COLORFGBG');
    }

    /**
     * The production call shape is the ARGUMENTLESS one:
     * {@see \SugarCraft\Crush\Theme::adaptive()} calls `isDark()` with no `$env`,
     * so the whole live path runs through `defaultEnv()` and its real
     * `getenv()` reads. Every other test in this class injects an explicit
     * array, which leaves that resolution step uncovered — with `$env ??=
     * self::defaultEnv()` reduced to `$env ??= []` the entire suite stays green
     * while `SUGARCRUSH_BACKGROUND` and `COLORFGBG` quietly stop reaching the
     * live adaptive theme. These two tests are the pin for that step.
     *
     * They set and restore the REAL process environment, so `finally` is not
     * optional: the suite shares one process, and a leaked
     * `SUGARCRUSH_BACKGROUND` would silently re-tier every later background
     * assertion in it.
     */
    public function testIsDarkReadsTheRealEnvironmentWhenNoneIsInjected(): void
    {
        $this->withEnv(function (): void {
            putenv(TerminalBackground::ENV_OVERRIDE . '=light');
            putenv('COLORFGBG=15;0'); // the environment guess says dark

            $this->assertFalse(
                TerminalBackground::isDark(),
                'the override reached the no-argument call shape production uses',
            );

            // ...and still does once the terminal has answered, which is the
            // tier ordering the live shell spends most of its life in.
            TerminalBackground::observe(new BackgroundColorMsg(0, 0, 0));
            $this->assertFalse(TerminalBackground::isDark(), 'override outranks the observed reply');

            // With the override gone, the no-argument shape falls through to
            // the real COLORFGBG rather than to an empty array.
            putenv(TerminalBackground::ENV_OVERRIDE);
            TerminalBackground::forget();
            putenv('COLORFGBG=0;15');
            $this->assertFalse(TerminalBackground::isDark(), 'COLORFGBG reached it too');
        });
    }

    /** The same uncovered line, on {@see TerminalBackground::detect()}. */
    public function testDetectReadsTheRealEnvironmentWhenNoneIsInjected(): void
    {
        $this->withEnv(function (): void {
            putenv(TerminalBackground::ENV_OVERRIDE);
            putenv('COLORFGBG=0;15');
            $this->assertFalse(TerminalBackground::detect(), 'a white background, read off the real env');

            putenv('COLORFGBG=15;0');
            $this->assertTrue(TerminalBackground::detect());

            putenv(TerminalBackground::ENV_OVERRIDE . '=light');
            $this->assertFalse(TerminalBackground::detect(), 'detect() checks the override itself');
        });
    }

    /**
     * Run $body with `SUGARCRUSH_BACKGROUND`/`COLORFGBG` restored afterwards —
     * including back to UNSET, which `putenv()` only does when handed a bare
     * name.
     */
    private function withEnv(callable $body): void
    {
        $keys = [TerminalBackground::ENV_OVERRIDE, 'COLORFGBG'];
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = getenv($key);
        }

        try {
            $body();
        } finally {
            foreach ($saved as $key => $value) {
                putenv(is_string($value) ? "{$key}={$value}" : $key);
            }
        }
    }

    public function testForgetRestoresTheEnvironmentFallback(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(255, 255, 255));
        TerminalBackground::forget();

        $this->assertNull(TerminalBackground::observed());
        $this->assertNull(TerminalBackground::observedColor());
        $this->assertTrue(TerminalBackground::isDark(['COLORFGBG' => '15;0']));
    }

    // =========================================================================
    // W3(b): the OSC 11 reply's RGB is retained, not reduced to one bit
    // =========================================================================

    /**
     * The whole point: `isDark()` cannot tell #0e0e14 from #333f58, and the
     * shell's chrome contrast differs by a factor of 3.4 between them. Both are
     * recorded from the ONE message so they can never disagree about which
     * terminal answered.
     */
    public function testObserveRetainsTheReportedColourBesideTheDarkBit(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(0x33, 0x3f, 0x58));

        $this->assertTrue(TerminalBackground::observed());
        $colour = TerminalBackground::observedColor();
        $this->assertNotNull($colour);
        $this->assertSame('#333f58', $colour->toHex());
        $this->assertSame([0x33, 0x3f, 0x58], [$colour->r, $colour->g, $colour->b]);
    }

    /**
     * Two different dark terminals must NOT collapse to the same answer — the
     * failure the one-bit memo had by construction.
     */
    public function testTwoDifferentDarkBackgroundsStayDistinguishable(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(0x0e, 0x0e, 0x14));
        $first = TerminalBackground::color();

        TerminalBackground::observe(new BackgroundColorMsg(0x33, 0x3f, 0x58));
        $second = TerminalBackground::color();

        $this->assertNotSame($first->toHex(), $second->toHex());
        // ...while the bit they both reduce to is identical, which is exactly
        // why the bit was not enough.
        $this->assertTrue($first->isDark());
        $this->assertTrue($second->isDark());
    }

    /**
     * `color()`'s precedence is `isDark()`'s, by construction: the explicit
     * override outranks even the terminal's own reply.
     */
    public function testColourFollowsTheSamePrecedenceAsIsDark(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(0, 0, 0));

        $this->assertSame('#000000', TerminalBackground::color(['COLORFGBG' => '0;15'])->toHex());

        // Override on top: a statement outranks a measurement, both here and in
        // isDark(), so the two can never disagree about which terminal to
        // resolve a theme against.
        $env = [TerminalBackground::ENV_OVERRIDE => 'light'];
        $this->assertSame('#ffffff', TerminalBackground::color($env)->toHex());
        $this->assertFalse(TerminalBackground::isDark($env));
    }

    /**
     * With no reply at all, `color()` falls back through `COLORFGBG` to a
     * NOMINAL black/white — a floor, not a measurement (see the method's
     * docblock), and it must agree with `isDark()` on the same input.
     */
    public function testColourFallsBackToTheNominalMonochromeWithNoReply(): void
    {
        $this->assertSame('#000000', TerminalBackground::color(['COLORFGBG' => '15;0'])->toHex());
        $this->assertSame('#ffffff', TerminalBackground::color(['COLORFGBG' => '0;15'])->toHex());

        foreach ([['COLORFGBG' => '15;0'], ['COLORFGBG' => '0;15'], []] as $env) {
            $this->assertSame(
                TerminalBackground::isDark($env),
                TerminalBackground::color($env)->isDark(),
                'color() and isDark() must not disagree about the same environment',
            );
        }
    }

    /**
     * ...and the same agreement on the tier that carries REAL rgb, which is the
     * only tier where the two can differ at all.
     *
     * The fallback test above is trivially satisfiable: there `color()` returns
     * `Color::ansi(0)`/`ansi(15)` and both rules call #000000 dark and #ffffff
     * light. The OSC 11 tier is where the split lived —
     * {@see BackgroundColorMsg::isDark()} averages the sRGB bytes while
     * {@see Color::isDark()} linearises first, so #808080 is 0.5019 (light) by
     * the message's rule and 0.2158 (dark) by WCAG's. MEASURED before
     * `observe()` was changed to derive the bit from the colour it keeps:
     * `detect(['COLORFGBG' => '0;244'])` said dark and `observe()` on the same
     * #808080 said light, so a terminal confirming by OSC 11 what COLORFGBG had
     * already reported FLIPPED the answer. The whole #808080-#b4b4b4 band
     * splits; three samples across it, plus the two ends as controls.
     */
    public function testTheObservedBitAgreesWithTheObservedColourOnRealRgb(): void
    {
        foreach ([[0, 0, 0], [0x28, 0x2a, 0x36], [128, 128, 128], [0xb4, 0xb4, 0xb4], [255, 255, 255]] as [$r, $g, $b]) {
            TerminalBackground::forget();
            TerminalBackground::observe(new BackgroundColorMsg($r, $g, $b));

            $colour = TerminalBackground::observedColor();
            self::assertNotNull($colour);
            $this->assertSame(
                $colour->isDark(),
                TerminalBackground::observed(),
                sprintf('#%02x%02x%02x: the bit and the colour must answer with one rule', $r, $g, $b),
            );
            $this->assertSame($colour->isDark(), TerminalBackground::isDark([]));
        }

        // The rule that is NOT used, pinned so the difference stays visible: a
        // #808080 terminal is dark to this shell and light to the message's own
        // gamma-naive test.
        TerminalBackground::forget();
        TerminalBackground::observe(new BackgroundColorMsg(128, 128, 128));
        $this->assertTrue(TerminalBackground::observed());
        $this->assertFalse((new BackgroundColorMsg(128, 128, 128))->isDark());
    }
}
