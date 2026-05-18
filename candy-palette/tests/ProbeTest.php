<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use SugarCraft\Palette\ColorProfile;
use SugarCraft\Palette\Probe;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SugarCraft\Palette\Probe
 */
final class ProbeTest extends TestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Preserve original env values so tearDown can restore them
        $keys = ['CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY', 'REDUCE_MOTION', 'PREFERS_REDUCED_MOTION'];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }
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

            // 2. NO_COLOR set (any value) → NoTTY
            'no_color set returns NoTTY' => [
                ['NO_COLOR' => '1', 'COLORTERM' => 'truecolor'],
                ColorProfile::NoTTY,
            ],
            'no_color empty string returns NoTTY' => [
                ['NO_COLOR' => ''],
                ColorProfile::NoTTY,
            ],

            // 3. CLICOLOR=0 → NoTTY
            'clicolor_0 returns NoTTY' => [
                ['CLICOLOR' => '0'],
                ColorProfile::NoTTY,
            ],

            // 4. TERM=dumb → NoTTY
            'term_dumb returns NoTTY' => [
                ['TERM' => 'dumb'],
                ColorProfile::NoTTY,
            ],

            // 5. COLORTERM=24bit|truecolor|yes → TrueColor
            'colorterm_24bit returns TrueColor' => [
                ['COLORTERM' => '24bit'],
                ColorProfile::TrueColor,
            ],
            'colorterm_truecolor returns TrueColor' => [
                ['COLORTERM' => 'truecolor'],
                ColorProfile::TrueColor,
            ],
            'colorterm_yes returns TrueColor' => [
                ['COLORTERM' => 'yes'],
                ColorProfile::TrueColor,
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
     * @covers \SugarCraft\Palette\Probe::colorProfile
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
     * @covers \SugarCraft\Palette\Probe::isNoColor
     */
    public function testIsNoColor(): void
    {
        $this->env('NO_COLOR', null);
        $this->assertFalse(Probe::isNoColor());

        $this->env('NO_COLOR', '1');
        $this->assertTrue(Probe::isNoColor());

        $this->env('NO_COLOR', '');
        $this->assertTrue(Probe::isNoColor());
    }

    /**
     * @covers \SugarCraft\Palette\Probe::isForceColor
     */
    public function testIsForceColor(): void
    {
        $this->env('CLICOLOR_FORCE', null);
        $this->assertFalse(Probe::isForceColor());

        $this->env('CLICOLOR_FORCE', '1');
        $this->assertTrue(Probe::isForceColor());

        $this->env('CLICOLOR_FORCE', '0');
        $this->assertFalse(Probe::isForceColor());
    }

    /**
     * @covers \SugarCraft\Palette\Probe::reducedMotion
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
}
