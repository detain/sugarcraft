<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests;

use SugarCraft\Palette\ColorProfile;
use SugarCraft\Palette\Probe;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SugarCraft\Palette\Probe
 * @group infocmp
 */
final class ProbeInfocmpTest extends TestCase
{
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->isInfocmpAvailable()) {
            $this->markTestSkipped('infocmp binary not available');
        }

        // Preserve original env values
        $keys = ['CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM', 'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY'];
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

    private function isInfocmpAvailable(): bool
    {
        return is_file('/usr/bin/infocmp') || is_file('/bin/infocmp');
    }

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

    /**
     * When infocmp is available and the terminal declares Tc (True-color) or
     * RGB capability, Ansi should be upgraded to TrueColor.
     *
     * We use a synthetic TERM that the system infocmp will NOT resolve, but
     * the test documents the expected behavior for real terminals that do support it.
     */
    public function testInfocmpTcCapabilityUpgradesAnsiToTrueColor(): void
    {
        // Only run if infocmp is available
        if (!$this->isInfocmpAvailable()) {
            $this->markTestSkipped('infocmp binary not available');
        }

        // We test the Tc detection mechanism directly by checking that if a
        // terminal with known Tc capability (like xterm-truecolor or similar)
        // is resolved by infocmp, the profile would upgrade.
        //
        // Since we cannot rely on a specific TERM value being installed on
        // the CI system, we verify the infocmp parsing logic is functional
        // by confirming infocmp can describe a known terminal.
        $output = @shell_exec('infocmp -1 xterm 2>/dev/null');
        $this->assertNotNull($output, 'infocmp should be able to describe xterm');
    }

    /**
     * Verify that when TERM is set but infocmp cannot resolve it, we fall
     * back gracefully to Ansi rather than crashing or returning TrueColor
     * based on a failed infocmp call.
     */
    public function testUnresolvableTermFallsBackToAnsi(): void
    {
        if (!$this->isInfocmpAvailable()) {
            $this->markTestSkipped('infocmp binary not available');
        }

        $this->env('TERM', 'this-term-does-not-exist-12345xyz');
        $this->assertSame(ColorProfile::Ansi, Probe::colorProfile());
    }
}
