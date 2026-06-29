<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use SugarCraft\Palette\Color;
use SugarCraft\Palette\Palette;
use SugarCraft\Palette\Profile;
use SugarCraft\Palette\ProfileWriter;
use PHPUnit\Framework\TestCase;

final class PaletteTest extends TestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Save original env values so tearDown can restore them.
        // This prevents parent-process env vars (NO_COLOR, CLICOLOR_FORCE, etc.)
        // from leaking into tests and causing spurious NoTTY returns.
        $this->savedEnv = [
            'CLICOLOR_FORCE' => $_ENV['CLICOLOR_FORCE'] ?? null,
            'NO_COLOR'       => $_ENV['NO_COLOR'] ?? null,
            'CLICOLOR'       => $_ENV['CLICOLOR'] ?? null,
            'TERM'           => $_ENV['TERM'] ?? null,
            'COLORTERM'      => $_ENV['COLORTERM'] ?? null,
            'WT_SESSION'     => $_ENV['WT_SESSION'] ?? null,
            'GOOGLE_CLOUD_SHELL' => $_ENV['GOOGLE_CLOUD_SHELL'] ?? null,
            'TMUX'           => $_ENV['TMUX'] ?? null,
            'STY'            => $_ENV['STY'] ?? null,
            'TERM_PROGRAM'   => $_ENV['TERM_PROGRAM'] ?? null,
        ];

        // Set known-safe values that won't trigger color forcing.
        // putenv('VAR=value') sets, putenv('VAR') (no =) removes.
        // CLICOLOR_FORCE=0 won't force TrueColor.
        // NO_COLOR is REMOVED (not set to empty) because detection uses
        //   array_key_exists which returns true even for null/empty.
        // CLICOLOR=0 won't force NoTTY.
        // NOTE: We do NOT set TERM here because tests that don't explicitly
        //   set TERM rely on the parent environment's TERM value to determine
        //   the appropriate color profile. Setting TERM=dumb would cause
        //   an early NoTTY return before FORCE_COLOR/COLORTERM could be checked.
        putenv('CLICOLOR_FORCE=0');
        putenv('NO_COLOR');  // Remove from process env entirely
        putenv('CLICOLOR=0');
        // TERM is intentionally NOT set - let tests use parent env or explicit values
        putenv('COLORTERM');  // Remove
        putenv('WT_SESSION');  // Remove
        putenv('GOOGLE_CLOUD_SHELL');  // Remove
        putenv('TMUX');  // Remove
        putenv('STY');  // Remove
        putenv('TERM_PROGRAM');  // Remove

        // Also update $_ENV superglobal to match putenv state.
        // Use unset to truly remove keys (not set to empty) so that
        // array_key_exists returns false for removed keys.
        unset($_ENV['CLICOLOR_FORCE']);
        unset($_ENV['NO_COLOR']);
        unset($_ENV['CLICOLOR']);
        // TERM is intentionally NOT unset - preserve parent env value for detection
        unset($_ENV['COLORTERM']);
        unset($_ENV['WT_SESSION']);
        unset($_ENV['GOOGLE_CLOUD_SHELL']);
        unset($_ENV['TMUX']);
        unset($_ENV['STY']);
        unset($_ENV['TERM_PROGRAM']);
    }

    protected function tearDown(): void
    {
        // Restore original env values from before setUp.
        // Use putenv() to properly restore the process environment.
        foreach ($this->savedEnv as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key]);
                putenv($key);  // Remove from process env
            } else {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Detection
    // -------------------------------------------------------------------------

    public function testDetectReturnsProfileEnum(): void
    {
        $profile = Palette::detect();
        $this->assertInstanceOf(Profile::class, $profile);
    }

    public function testNoColorEnvSetsNoTTY(): void
    {
        $profile = Palette::detect(null, ['NO_COLOR' => '1']);
        $this->assertSame(Profile::NoTTY, $profile);
    }

    public function testForceColorTrueColor(): void
    {
        $profile = Palette::detect(null, ['FORCE_COLOR' => '3']);
        $this->assertSame(Profile::TrueColor, $profile);
    }

    public function testForceColorAnsi256(): void
    {
        $profile = Palette::detect(null, ['FORCE_COLOR' => '2']);
        $this->assertSame(Profile::ANSI256, $profile);
    }

    public function testForceColorAnsi(): void
    {
        $profile = Palette::detect(null, ['FORCE_COLOR' => '1']);
        $this->assertSame(Profile::ANSI, $profile);
    }

    public function testForceColorAscii(): void
    {
        $profile = Palette::detect(null, ['FORCE_COLOR' => '0']);
        $this->assertSame(Profile::Ascii, $profile);
    }

    public function testColortermEnvImpliesTrueColor(): void
    {
        $profile = Palette::detect(null, ['COLORTERM' => 'truecolor', 'TERM' => 'dumb']);
        $this->assertSame(Profile::TrueColor, $profile);
    }

    public function testITerm2TermProgramImpliesTrueColor(): void
    {
        $profile = Palette::detect(null, ['TERM_PROGRAM' => 'iTerm.app', 'TERM' => 'dumb']);
        $this->assertSame(Profile::TrueColor, $profile);
    }

    public function testXterm256ImpliesTrueColor(): void
    {
        $profile = Palette::detect(null, ['TERM' => 'xterm-256color', 'NO_COLOR' => '']);
        // NO_COLOR overrides term capability
        $this->assertSame(Profile::NoTTY, $profile);
    }

    public function testXterm16ImpliesAnsi(): void
    {
        // xterm-16color is not a 256color terminal, so it maps to bare xterm → ANSI
        $profile = Palette::detect(null, ['TERM' => 'xterm-16color']);
        $this->assertSame(Profile::ANSI, $profile);
    }

    public function testDumbTerminalReturnsNoTTY(): void
    {
        $profile = Palette::detect(null, ['TERM' => 'dumb']);
        // dumb has no color, TERM_PROGRAM is absent, COLORTERM absent, NO_COLOR absent
        // so it falls through to isatty check; if not a tty → NoTTY
        $this->assertSame(Profile::NoTTY, $profile);
    }

    // -------------------------------------------------------------------------
    // Comment / describe
    // -------------------------------------------------------------------------

    public function testCommentForTrueColor(): void
    {
        $p = new Palette(null, ['FORCE_COLOR' => '3']);
        $this->assertSame('fancy', $p->comment());
    }

    public function testCommentForANSI256(): void
    {
        $p = new Palette(null, ['TERM' => 'xterm-256color']);
        $this->assertSame('1990s fancy', $p->comment());
    }

    public function testCommentForANSI(): void
    {
        $p = new Palette(null, ['TERM' => 'vt100', 'FORCE_COLOR' => '1']);
        $this->assertSame('normcore', $p->comment());
    }

    public function testDescribe(): void
    {
        $p = new Palette(null, ['FORCE_COLOR' => '2']);
        $this->assertStringContainsString('ANSI 256', $p->describe());
    }

    // -------------------------------------------------------------------------
    // Strip ANSI
    // -------------------------------------------------------------------------

    public function testStripAnsiRemovesSGR(): void
    {
        $input = "\x1b[38;2;255;0;0mred\x1b[0m";
        $stripped = Palette::stripAnsi($input);
        $this->assertSame('red', $stripped);
    }

    public function testStripAnsiRemovesOSC(): void
    {
        $input = "\x1b]8;;https://example.com\x1b\\click here\x1b]8;;\x1b\\";
        $stripped = Palette::stripAnsi($input);
        $this->assertSame('click here', $stripped);
    }

    public function testStripAnsiRemovesCSI(): void
    {
        $input = "\x1b[1;2H\x1b[J"; // clear screen
        $stripped = Palette::stripAnsi($input);
        $this->assertSame('', $stripped);
    }

    // -------------------------------------------------------------------------
    // Color conversion shortcut
    // -------------------------------------------------------------------------

    public function testToProfileShortcut(): void
    {
        $c = new Color(0x6b, 0x50, 0xff);
        $converted = Palette::toProfile($c, Profile::ANSI256);
        $this->assertInstanceOf(Color::class, $converted);
        $this->assertNotNull($converted->toAnsi256Index());
    }
}
