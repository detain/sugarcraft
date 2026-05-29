<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Probe\Capability;
use SugarCraft\Palette\Probe\ProbeReport;
use SugarCraft\Palette\Probe\TerminalProbe;

/**
 * @covers \SugarCraft\Palette\Probe\TerminalProbe
 * @covers \SugarCraft\Palette\Probe\ProbeReport
 * @covers \SugarCraft\Palette\Probe\Capability
 */
final class TerminalProbeTest extends TestCase
{
    /** @var array<string,string|null> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Preserve original env values so tearDown can restore them
        $keys = [
            'CLICOLOR_FORCE', 'NO_COLOR', 'CLICOLOR', 'TERM', 'COLORTERM',
            'WT_SESSION', 'GOOGLE_CLOUD_SHELL', 'TMUX', 'STY', 'TERM_PROGRAM',
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

    /**
     * @return array<string, array{0: array<string, string|null>, 1: list<Capability>}>
     */
    public static function capabilityProvider(): array
    {
        return [
            // 1. CLICOLOR_FORCE=1 → TrueColor (overrides everything below)
            'clicolor_force_1 returns TrueColor' => [
                ['CLICOLOR_FORCE' => '1', 'TERM' => 'dumb', 'NO_COLOR' => '1'],
                [Capability::TrueColor, Capability::BasicAscii],
            ],

            // 2. NO_COLOR set (any value) → NoColor
            'no_color set returns NoColor' => [
                ['NO_COLOR' => '1', 'COLORTERM' => 'truecolor'],
                [Capability::NoColor, Capability::BasicAscii],
            ],
            'no_color empty string returns NoColor' => [
                ['NO_COLOR' => ''],
                [Capability::NoColor, Capability::BasicAscii],
            ],

            // 3. CLICOLOR=0 → NoColor
            'clicolor_0 returns NoColor' => [
                ['CLICOLOR' => '0'],
                [Capability::NoColor, Capability::BasicAscii],
            ],

            // 4. TERM=dumb → NoColor
            'term_dumb returns NoColor' => [
                ['TERM' => 'dumb'],
                [Capability::NoColor, Capability::BasicAscii],
            ],

            // 5. COLORTERM=24bit|truecolor|yes → TrueColor
            'colorterm_24bit returns TrueColor' => [
                ['COLORTERM' => '24bit'],
                [Capability::TrueColor, Capability::BasicAscii],
            ],
            'colorterm_truecolor returns TrueColor' => [
                ['COLORTERM' => 'truecolor'],
                [Capability::TrueColor, Capability::BasicAscii],
            ],
            'colorterm_yes returns TrueColor' => [
                ['COLORTERM' => 'yes'],
                [Capability::TrueColor, Capability::BasicAscii],
            ],

            // 6. WT_SESSION set → TrueColor (Windows Terminal)
            'wt_session set returns TrueColor' => [
                ['WT_SESSION' => '1'],
                [Capability::TrueColor, Capability::BasicAscii],
            ],

            // 7. GOOGLE_CLOUD_SHELL=true → TrueColor
            'google_cloud_shell true returns TrueColor' => [
                ['GOOGLE_CLOUD_SHELL' => 'true'],
                [Capability::TrueColor, Capability::BasicAscii],
            ],

            // 8. TMUX set + screen → Color256
            'tmux set with screen returns Color256' => [
                ['TMUX' => '1234', 'TERM' => 'screen-256color'],
                [Capability::Color256, Capability::BasicAscii],
            ],
            'tmux set with tmux → Color256' => [
                ['TMUX' => '1234', 'TERM' => 'tmux-256color'],
                [Capability::Color256, Capability::BasicAscii],
            ],
            'sty set with screen returns Color256' => [
                ['STY' => '1234', 'TERM' => 'screen-256color'],
                [Capability::Color256, Capability::BasicAscii],
            ],
            'tmux set with xterm-256color returns Color256' => [
                ['TMUX' => '1234', 'TERM' => 'xterm-256color'],
                [Capability::Color256, Capability::BasicAscii],
            ],

            // 9. TERM=xterm-kitty → Color256
            'term_xterm_kitty returns Color256' => [
                ['TERM' => 'xterm-kitty'],
                [Capability::Color256, Capability::BasicAscii, Capability::KittyKeyboard],
            ],
            // 9. TERM=xterm-ghostty → Color256
            'term_xterm_ghostty returns Color256' => [
                ['TERM' => 'xterm-ghostty'],
                [Capability::Color256, Capability::BasicAscii],
            ],
            // 9. TERM=*-256color → Color256
            'term_something_256color returns Color256' => [
                ['TERM' => 'xterm-256color'],
                [Capability::Color256, Capability::BasicAscii],
            ],
            'term_screen_256color returns Color256' => [
                ['TERM' => 'screen-256color'],
                [Capability::Color256, Capability::BasicAscii],
            ],

            // 10. TERM=xterm* → Color16
            'term_xterm returns Color16' => [
                ['TERM' => 'xterm'],
                [Capability::Color16, Capability::BasicAscii],
            ],
            'term_xterm_color returns Color16' => [
                ['TERM' => 'xterm-color'],
                [Capability::Color16, Capability::BasicAscii],
            ],
            // 10. TERM=screen* → Color16
            'term_screen returns Color16' => [
                ['TERM' => 'screen'],
                [Capability::Color16, Capability::BasicAscii],
            ],
            // 10. TERM=tmux* → Color16
            'term_tmux returns Color16' => [
                ['TERM' => 'tmux'],
                [Capability::Color16, Capability::BasicAscii],
            ],

            // 11. Default (no TERM set) → Color16
            'default (no term set) returns Color16' => [
                [],
                [Capability::Color16, Capability::BasicAscii],
            ],
        ];
    }

    /**
     * @dataProvider capabilityProvider
     */
    public function testProbeCapabilities(array $env, array $expectedCapabilities): void
    {
        foreach ($env as $key => $value) {
            $this->env($key, $value);
        }

        $report = TerminalProbe::run();

        foreach ($expectedCapabilities as $cap) {
            $this->assertTrue(
                $report->has($cap),
                "Expected capability {$cap->value} not found. Got: " . implode(', ', array_map(fn($c) => $c->value, $report->all()))
            );
        }
    }

    public function testSourceStringsAreNonEmpty(): void
    {
        $this->env('TERM', 'xterm-256color');
        $this->env('COLORTERM', 'truecolor');

        $report = TerminalProbe::run();

        foreach ($report->all() as $cap) {
            $source = $report->source($cap);
            $this->assertNotNull($source, "Source for {$cap->value} should not be null");
            $this->assertNotEmpty($source, "Source for {$cap->value} should not be empty");
        }
    }

    public function testProbeReportHas(): void
    {
        $this->env('TERM', 'xterm-256color');

        $report = TerminalProbe::run();

        $this->assertTrue($report->has(Capability::Color256));
        $this->assertTrue($report->has(Capability::BasicAscii));
        $this->assertFalse($report->has(Capability::TrueColor));
        $this->assertFalse($report->has(Capability::NoColor));
    }

    public function testProbeReportSource(): void
    {
        $this->env('COLORTERM', 'truecolor');

        $report = TerminalProbe::run();

        $this->assertNotNull($report->source(Capability::TrueColor));
        $this->assertStringStartsWith('env:', $report->source(Capability::TrueColor));
    }

    public function testProbeReportAll(): void
    {
        $this->env('TERM', 'xterm-256color');

        $report = TerminalProbe::run();

        $all = $report->all();
        $this->assertIsArray($all);
        $this->assertNotEmpty($all);
        $this->assertContainsOnly(Capability::class, $all);
    }

    public function testProbeReportColorDescription(): void
    {
        $this->env('COLORTERM', 'truecolor');
        $report = TerminalProbe::run();
        $this->assertStringContainsString('TrueColor', $report->colorDescription());

        $this->env('COLORTERM', null);
        $this->env('TERM', 'xterm-256color');
        $report = TerminalProbe::run();
        $this->assertStringContainsString('256', $report->colorDescription());
    }

    public function testBasicAsciiAlwaysPresent(): void
    {
        $this->env('TERM', 'dumb');

        $report = TerminalProbe::run();

        $this->assertTrue($report->has(Capability::BasicAscii));
    }

    public function testNoColorDetectedWhenNoColorEnvSet(): void
    {
        $this->env('NO_COLOR', '1');
        $this->env('COLORTERM', 'truecolor');

        $report = TerminalProbe::run();

        $this->assertTrue($report->has(Capability::NoColor));
    }

    public function testCliColorForceOverrides(): void
    {
        $this->env('CLICOLOR_FORCE', '1');
        $this->env('NO_COLOR', '1');
        $this->env('TERM', 'dumb');

        $report = TerminalProbe::run();

        $this->assertTrue($report->has(Capability::TrueColor));
    }

    public function testDetectedAtTimestamp(): void
    {
        $before = new \DateTimeImmutable();
        $report = TerminalProbe::run();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $report->detectedAt);
        $this->assertLessThanOrEqual($after, $report->detectedAt);
    }

    public function testCheckEscapeQueriesWithITerm(): void
    {
        $this->env('TERM_PROGRAM', 'iTerm.app');
        $this->env('TERM', '');

        $probe = new class(['TERM_PROGRAM' => 'iTerm.app', 'TERM' => '']) extends TerminalProbe {
            protected function isInteractive(): bool
            {
                return true;
            }
        };
        $report = $probe->runProbe([], true);

        $this->assertTrue($report->has(Capability::ITerm2));
        $this->assertStringStartsWith('env:TERM_PROGRAM=', $report->source(Capability::ITerm2));
    }

    public function testCheckEscapeQueriesWithAppleTerminal(): void
    {
        $this->env('TERM_PROGRAM', 'Apple_Terminal');
        $this->env('TERM', '');

        $probe = new class(['TERM_PROGRAM' => 'Apple_Terminal', 'TERM' => '']) extends TerminalProbe {
            protected function isInteractive(): bool
            {
                return true;
            }
        };
        $report = $probe->runProbe([], true);

        $this->assertTrue($report->has(Capability::ITerm2));
    }

    public function testCheckEscapeQueriesWithHyper(): void
    {
        $this->env('TERM_PROGRAM', 'Hyper');
        $this->env('TERM', '');

        $probe = new class(['TERM_PROGRAM' => 'Hyper', 'TERM' => '']) extends TerminalProbe {
            protected function isInteractive(): bool
            {
                return true;
            }
        };
        $report = $probe->runProbe([], true);

        $this->assertTrue($report->has(Capability::Hyperlinks));
    }

    public function testCheckEscapeQueriesWithWezTerm(): void
    {
        $this->env('TERM_PROGRAM', 'WezTerm');
        $this->env('TERM', '');

        $probe = new class(['TERM_PROGRAM' => 'WezTerm', 'TERM' => '']) extends TerminalProbe {
            protected function isInteractive(): bool
            {
                return true;
            }
        };
        $report = $probe->runProbe([], true);

        $this->assertTrue($report->has(Capability::TrueColor));
    }

    public function testCheckEscapeQueriesWithVscode(): void
    {
        $this->env('TERM_PROGRAM', 'vscode');
        $this->env('TERM', '');

        $probe = new class(['TERM_PROGRAM' => 'vscode', 'TERM' => '']) extends TerminalProbe {
            protected function isInteractive(): bool
            {
                return true;
            }
        };
        $report = $probe->runProbe([], true);

        $this->assertTrue($report->has(Capability::TrueColor));
    }

    public function testCheckEscapeQueriesWithGhostty(): void
    {
        $this->env('TERM_PROGRAM', 'Ghostty');
        $this->env('TERM', '');

        $probe = new class(['TERM_PROGRAM' => 'Ghostty', 'TERM' => '']) extends TerminalProbe {
            protected function isInteractive(): bool
            {
                return true;
            }
        };
        $report = $probe->runProbe([], true);

        $this->assertTrue($report->has(Capability::TrueColor));
    }

    public function testCheckEscapeQueriesWithXtermKittyKeyboard(): void
    {
        $this->env('TERM', 'xterm-kitty');

        $probe = new class(['TERM' => 'xterm-kitty']) extends TerminalProbe {
            protected function isInteractive(): bool
            {
                return true;
            }
        };
        $report = $probe->runProbe([], true);

        $this->assertTrue($report->has(Capability::KittyKeyboard));
    }

    public function testProbeWithTermDumbNonInteractive(): void
    {
        $this->env('TERM', 'dumb');

        $report = TerminalProbe::run();

        $this->assertTrue($report->has(Capability::NoColor));
        $this->assertTrue($report->has(Capability::BasicAscii));
    }

    public function testProbeWithEmptyTermInteractive(): void
    {
        $this->env('TERM', '');

        $report = TerminalProbe::run();

        $this->assertTrue($report->has(Capability::Color16));
    }

    public function testCheckTerminfoWithTcAndRgb(): void
    {
        $this->env('TERM', 'xterm-256color');

        $probe = new class(['TERM' => 'xterm-256color']) extends TerminalProbe {
            protected function infocmpAvailable(): bool { return true; }
            protected function runCommand(string $cmd): ?string {
                if (str_contains($cmd, 'infocmp')) {
                    return " Tc RGB sgr0 sitm ritm\n";
                }
                return null;
            }
        };
        $report = $probe->runProbe();

        $this->assertTrue($report->has(Capability::TrueColor));
        $this->assertSame('terminfo:Tc|RGB', $report->source(Capability::TrueColor));
    }

    public function testCheckTerminfoWithSixel(): void
    {
        $this->env('TERM', 'xterm');

        $probe = new class(['TERM' => 'xterm']) extends TerminalProbe {
            protected function infocmpAvailable(): bool { return true; }
            protected function runCommand(string $cmd): ?string {
                if (str_contains($cmd, 'infocmp')) {
                    return "sixel\nrsgr0\n";
                }
                return null;
            }
        };
        $report = $probe->runProbe();

        $this->assertTrue($report->has(Capability::Sixel));
        $this->assertSame('terminfo:sixel', $report->source(Capability::Sixel));
    }

    public function testApplyFallbacksAddsColor16WhenNoColorCapabilities(): void
    {
        $this->env('TERM', 'dumb');

        $probe = new class(['TERM' => 'dumb']) extends TerminalProbe {
            protected function isInteractive(): bool { return false; }
        };
        $report = $probe->runProbe();

        $this->assertTrue($report->has(Capability::BasicAscii));
        $this->assertTrue($report->has(Capability::NoColor));
    }

    public function testCheckEscapeQueriesWithNullTermProgram(): void
    {
        $this->env('TERM_PROGRAM', '');
        $this->env('TERM', 'xterm');

        $probe = new class(['TERM_PROGRAM' => '', 'TERM' => 'xterm']) extends TerminalProbe {
            protected function isInteractive(): bool { return true; }
        };
        $report = $probe->runProbe([], true);

        $this->assertFalse($report->has(Capability::ITerm2));
        $this->assertFalse($report->has(Capability::Hyperlinks));
        $this->assertTrue($report->has(Capability::BasicAscii));
    }
}
