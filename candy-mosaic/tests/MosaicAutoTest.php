<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Palette\Probe\Capability;
use SugarCraft\Palette\Probe\TerminalProbe;

/**
 * @covers \SugarCraft\Mosaic\Mosaic::auto
 * @covers \SugarCraft\Mosaic\Mosaic::diagnose
 */
final class MosaicAutoTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $keys = [
            'CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM',
            'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY', 'TERM_PROGRAM',
            'KITTY_WINDOW_ID', 'XTERM_VERSION', 'LC_TERMINAL',
        ];
        foreach ($keys as $key) {
            $this->savedEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
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
     */
    private function env(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            putenv($key);
        } else {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    public function testAutoReturnsMosaicInstance(): void
    {
        $mosaic = Mosaic::auto();
        $this->assertInstanceOf(Mosaic::class, $mosaic);
    }

    public function testAutoNeverThrows(): void
    {
        // Even with completely invalid environment, auto() should not throw
        $this->env('TERM', 'invalid-term-xyz');
        $this->env('COLORTERM', null);

        $mosaic = Mosaic::auto();
        $this->assertInstanceOf(Mosaic::class, $mosaic);
    }

    public function testAutoWithNoColorFallsBackToHalfBlock(): void
    {
        $this->env('NO_COLOR', '1');

        $mosaic = Mosaic::auto();

        // Should fall back to HalfBlock (always available)
        $this->assertSame('halfblock', $mosaic->protocol());
    }

    public function testAutoWithTermDumbFallsBackToHalfBlock(): void
    {
        $this->env('TERM', 'dumb');

        $mosaic = Mosaic::auto();

        // Should fall back to HalfBlock (always available)
        $this->assertSame('halfblock', $mosaic->protocol());
    }

    public function testDiagnoseReturnsProbeReport(): void
    {
        $this->env('TERM', 'xterm-256color');

        $report = Mosaic::diagnose();

        $this->assertInstanceOf(\SugarCraft\Palette\Probe\ProbeReport::class, $report);
    }

    public function testDiagnoseHasBasicAscii(): void
    {
        $this->env('TERM', 'xterm-256color');

        $report = Mosaic::diagnose();

        $this->assertTrue($report->has(Capability::BasicAscii));
    }

    public function testDiagnoseHasColor256ForXterm256color(): void
    {
        $this->env('TERM', 'xterm-256color');

        $report = Mosaic::diagnose();

        $this->assertTrue($report->has(Capability::Color256));
    }

    public function testDiagnoseHasTrueColorForColortermTruecolor(): void
    {
        $this->env('COLORTERM', 'truecolor');

        $report = Mosaic::diagnose();

        $this->assertTrue($report->has(Capability::TrueColor));
    }

    public function testDiagnoseHasNoColorForNoColorEnv(): void
    {
        $this->env('NO_COLOR', '1');

        $report = Mosaic::diagnose();

        $this->assertTrue($report->has(Capability::NoColor));
    }

    public function testAutoWithXtermKitty(): void
    {
        $this->env('TERM', 'xterm-kitty');

        $mosaic = Mosaic::auto();

        // Should detect Kitty capability
        $this->assertSame('kitty', $mosaic->protocol());
    }

    public function testAutoWithTermProgramIterm(): void
    {
        $this->env('TERM_PROGRAM', 'iTerm.app');

        $mosaic = Mosaic::auto();

        // Should detect iTerm2 capability
        $this->assertSame('iterm2', $mosaic->protocol());
    }

    public function testAutoSourceStringNonEmpty(): void
    {
        $this->env('TERM', 'xterm-256color');

        $report = Mosaic::diagnose();

        foreach ($report->all() as $cap) {
            $source = $report->source($cap);
            $this->assertNotNull($source, "Source for {$cap->value} should not be null");
            $this->assertNotEmpty($source, "Source for {$cap->value} should not be empty");
        }
    }

    public function testAutoCliColorForceOverrides(): void
    {
        $this->env('CLICOLOR_FORCE', '1');
        $this->env('NO_COLOR', '1');
        $this->env('TERM', 'dumb');

        $mosaic = Mosaic::auto();

        // CLICOLOR_FORCE=1 forces TrueColor, so should still work
        $this->assertInstanceOf(Mosaic::class, $mosaic);
    }
}
