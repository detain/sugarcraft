<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\ColorProfile;
use SugarCraft\ColorProfile\Probe;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;

/**
 * @covers \SugarCraft\ColorProfile\Probe
 */
final class ProbeTest extends TestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Preserve original env values so tearDown can restore them.
        // FORCE_COLOR joins the list (CP-26): a developer/CI shell
        // exporting it outranks every TERM rung asserted below.
        $keys = ['CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM', 'FORCE_COLOR', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY', 'REDUCE_MOTION', 'PREFERS_REDUCED_MOTION'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }

        // CP-02 hermeticity: drain the shared exec statics (without this the
        // per-TERM dump memo survives method — and file — boundaries) and
        // point the binary sniff at its ABSENT sentinel so phase-2 never
        // execs the host infocmp. The rows below then answer from the env
        // ladder alone: a host whose terminfo entries carry a name-position
        // Tc/RGB (kitty and ghostty ship those; some distros graft Tc onto
        // xterm-256color) can no longer flip an Ansi256 expectation to
        // TrueColor here. Genuine terminfo-promotion coverage stays
        // fixture-driven where it already lives (InfocmpEnvPurityTest's
        // stuffed Tc-printer) — it is not duplicated here.
        Probe::_reset();
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, '');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Restore original env values
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        // Drain the stuffed sentinel + any recorded miss so the next suite
        // inherits a clean door (the in-suite hermetic idiom, CP-26 wave).
        Probe::_reset();
    }

    /**
     * Sets an env var for the test duration.
     * Always uses putenv() so both $_ENV and getenv() agree.
     */
    private function env(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $value;
            // putenv() so getenv() also returns the right value
            putenv("{$key}={$value}");
        }
    }

    /**
     * @return array<string, array{0: array<string, string|null>, 1: ColorProfile}>
     */
    public static function colorProfileProvider(): array
    {
        return [
            // 1. CLICOLOR_FORCE=1 → TrueColor (overrides everything below)
            'clicolor_force_1 returns TrueColor' => [
                ['CLICOLOR_FORCE' => '1', 'TERM' => 'dumb', 'NO_COLOR' => '1'],
                ColorProfile::TrueColor,
            ],

            // 2. NO_COLOR set (any value) → Ascii (colorless rung — CP-05)
            'no_color set returns ascii' => [
                ['NO_COLOR' => '1', 'COLORTERM' => 'truecolor'],
                ColorProfile::Ascii,
            ],
            // no-color.org: NO_COLOR="" is UNSET — falls through (flipped with MT2).
            'no_color empty string is unset' => [
                ['NO_COLOR' => ''],
                ColorProfile::Ansi,
            ],

            // 3. CLICOLOR=0 → Ascii (colorless rung — CP-05)
            'clicolor_0 returns ascii' => [
                ['CLICOLOR' => '0'],
                ColorProfile::Ascii,
            ],

            // 4. TERM=dumb → Ascii (colorless rung — CP-05)
            'term_dumb returns ascii' => [
                ['TERM' => 'dumb'],
                ColorProfile::Ascii,
            ],

            // 5. COLORTERM=truecolor|24bit → TrueColor; yes → Ansi256 (CP-09)
            'colorterm_24bit returns TrueColor' => [
                ['COLORTERM' => '24bit'],
                ColorProfile::TrueColor,
            ],
            'colorterm_truecolor returns TrueColor' => [
                ['COLORTERM' => 'truecolor'],
                ColorProfile::TrueColor,
            ],
            'colorterm_yes returns Ansi256' => [
                ['COLORTERM' => 'yes'],
                ColorProfile::Ansi256,
            ],

            // 6. WT_SESSION set → TrueColor (Windows Terminal)
            'wt_session set returns TrueColor' => [
                ['WT_SESSION' => '1'],
                ColorProfile::TrueColor,
            ],

            // 7. GOOGLE_CLOUD_SHELL=true → TrueColor
            'google_cloud_shell true returns TrueColor' => [
                ['GOOGLE_CLOUD_SHELL' => 'true'],
                ColorProfile::TrueColor,
            ],

            // 8. TMUX set + screen → Ansi256
            'tmux set with screen returns Ansi256' => [
                ['TMUX' => '1234', 'TERM' => 'screen-256color'],
                ColorProfile::Ansi256,
            ],
            'tmux set with tmux → Ansi256' => [
                ['TMUX' => '1234', 'TERM' => 'tmux-256color'],
                ColorProfile::Ansi256,
            ],
            // 8. STY set + screen → Ansi256
            'sty set with screen returns Ansi256' => [
                ['STY' => '1234', 'TERM' => 'screen-256color'],
                ColorProfile::Ansi256,
            ],
            // 8. TMUX set but xterm-256color → falls through to Ansi256 via rule 9
            'tmux set with xterm-256color returns Ansi256' => [
                ['TMUX' => '1234', 'TERM' => 'xterm-256color'],
                ColorProfile::Ansi256,
            ],

            // 9. TERM=xterm-kitty → Ansi256
            'term_xterm_kitty returns Ansi256' => [
                ['TERM' => 'xterm-kitty'],
                ColorProfile::Ansi256,
            ],
            // 9. TERM=xterm-ghostty → Ansi256
            'term_xterm_ghostty returns Ansi256' => [
                ['TERM' => 'xterm-ghostty'],
                ColorProfile::Ansi256,
            ],
            // 9. TERM=*-256color → Ansi256
            'term_something_256color returns Ansi256' => [
                ['TERM' => 'xterm-256color'],
                ColorProfile::Ansi256,
            ],
            'term_screen_256color returns Ansi256' => [
                ['TERM' => 'screen-256color'],
                ColorProfile::Ansi256,
            ],

            // 10. TERM=xterm* → Ansi
            'term_xterm returns Ansi' => [
                ['TERM' => 'xterm'],
                ColorProfile::Ansi,
            ],
            'term_xterm_color returns Ansi' => [
                ['TERM' => 'xterm-color'],
                ColorProfile::Ansi,
            ],
            // 10. TERM=screen* → Ansi
            'term_screen returns Ansi' => [
                ['TERM' => 'screen'],
                ColorProfile::Ansi,
            ],
            // 10. TERM=tmux* → Ansi
            'term_tmux returns Ansi' => [
                ['TERM' => 'tmux'],
                ColorProfile::Ansi,
            ],

            // 11. Default (no TERM set) → Ansi
            'default (no term set) returns Ansi' => [
                [],
                ColorProfile::Ansi,
            ],
        ];
    }

    /**
     * @covers \SugarCraft\ColorProfile\Probe::colorProfile
     * @dataProvider colorProfileProvider
     */
    public function testColorProfile(array $env, ColorProfile $expected): void
    {
        foreach ($env as $key => $value) {
            $this->env($key, $value);
        }

        $this->assertSame($expected, Probe::colorProfile());
    }

    /**
     * @covers \SugarCraft\ColorProfile\Probe::isNoColor
     */
    public function testIsNoColor(): void
    {
        $this->env('NO_COLOR', null);
        $this->assertFalse(Probe::isNoColor());

        $this->env('NO_COLOR', '1');
        $this->assertTrue(Probe::isNoColor());

        $this->env('NO_COLOR', '');
        // no-color.org: set-but-empty means UNSET (MT2 cure; the old pin
        // disabled color for an exported empty variable).
        $this->assertFalse(Probe::isNoColor());
    }

    /**
     * @covers \SugarCraft\ColorProfile\Probe::isForceColor
     */
    public function testIsForceColor(): void
    {
        $this->env('CLICOLOR_FORCE', null);
        $this->assertFalse(Probe::isForceColor());

        $this->env('CLICOLOR_FORCE', '1');
        $this->assertTrue(Probe::isForceColor());

        $this->env('CLICOLOR_FORCE', '0');
        $this->assertFalse(Probe::isForceColor());

        // CP-08 (XPLAT-02): the termenv/chafa grammar — the old literal
        // '1'-only reading silently stripped color for the spellings real
        // dotfiles and CI images export.
        $this->env('CLICOLOR_FORCE', 'true');
        $this->assertTrue(Probe::isForceColor());

        $this->env('CLICOLOR_FORCE', 'yes');
        $this->assertTrue(Probe::isForceColor());

        $this->env('CLICOLOR_FORCE', 'on');
        $this->assertTrue(Probe::isForceColor());

        // The empty export is the unset spelling (no-color.org semantics
        // shared with NO_COLOR); '0' stays the only falsy word.
        $this->env('CLICOLOR_FORCE', '');
        $this->assertFalse(Probe::isForceColor());
    }

    /**
     * @covers \SugarCraft\ColorProfile\Probe::reducedMotion
     */
    public function testReducedMotion(): void
    {
        $this->env('REDUCE_MOTION', null);
        $this->env('PREFERS_REDUCED_MOTION', null);
        $this->assertFalse(Probe::reducedMotion());

        $this->env('REDUCE_MOTION', '1');
        $this->assertTrue(Probe::reducedMotion());

        $this->env('REDUCE_MOTION', '0');
        $this->env('PREFERS_REDUCED_MOTION', '1');
        $this->assertTrue(Probe::reducedMotion());

        $this->env('REDUCE_MOTION', null);
        $this->env('PREFERS_REDUCED_MOTION', null);
        $this->assertFalse(Probe::reducedMotion());
    }

    /**
     * @covers \SugarCraft\ColorProfile\Probe::_reset
     */
    public function testResetClearsStaticCache(): void
    {
        // The old body was `_reset(); assertTrue(true)` — a tautology that
        // stayed green no matter what the seam did. Stuff BOTH statics the
        // seam owns, reset, and prove they actually drained. (CP-11 moved
        // the phase-2 verdict table into the exec door; it drains here.)
        $memo = new \ReflectionProperty(InfocmpBinary::class, 'dumpMemo');
        $memo->setValue(null, ['xterm' => "\tTc,\n", 'vt100' => null]);
        $path = new \ReflectionProperty(InfocmpBinary::class, 'path');
        $path->setValue(null, '/usr/bin/infocmp');

        Probe::_reset();

        $this->assertSame([], $memo->getValue(), 'the shared dump memo must drain');
        $this->assertNull($path->getValue(), 'the binary-path memo must drain');
    }
}
