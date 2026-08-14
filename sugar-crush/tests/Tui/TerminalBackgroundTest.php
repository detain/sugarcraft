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
        $this->assertTrue(TerminalBackground::isDark(['COLORFGBG' => '15;0']));
    }
}
