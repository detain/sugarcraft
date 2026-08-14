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

    public function testForgetRestoresTheEnvironmentFallback(): void
    {
        TerminalBackground::observe(new BackgroundColorMsg(255, 255, 255));
        TerminalBackground::forget();

        $this->assertNull(TerminalBackground::observed());
        $this->assertTrue(TerminalBackground::isDark(['COLORFGBG' => '15;0']));
    }
}
