<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\ProbeReport;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\TerminalProbe;

/**
 * @covers \SugarCraft\ColorProfile\Probe\TerminalProbe
 * @covers \SugarCraft\ColorProfile\Probe\ProbeReport
 * @covers \SugarCraft\ColorProfile\Probe\Capability
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
            // no-color.org: NO_COLOR="" is UNSET — detection falls through
            // to the TERM rungs (flipped with the MT2 cure).
            'no_color empty string is unset' => [
                ['NO_COLOR' => ''],
                [Capability::Color16, Capability::BasicAscii],
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
            // dumb outranks an advertised COLORTERM (unified ladder)
            'term_dumb vetoes colorterm' => [
                ['COLORTERM' => 'truecolor', 'TERM' => 'dumb'],
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

        // Apple_Terminal must NOT advertise ITerm2: Terminal.app has no
        // OSC 1337 inline-image protocol, and the false positive made
        // sugar-image's Mosaic pick the iTerm2 wire on a terminal that
        // renders it as garbage.
        $this->assertFalse($report->has(Capability::ITerm2));
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

    public function testCheckTerminfoExecsTheResolvedBinaryNotABarePathLookup(): void
    {
        $this->env('TERM', 'xterm-256color');

        $probe = new class(['TERM' => 'xterm-256color']) extends TerminalProbe {
            public ?string $capturedCommand = null;

            protected function infocmpAvailable(): bool
            {
                return true;
            }

            protected function runCommand(string $cmd): ?string
            {
                if (str_contains($cmd, 'infocmp')) {
                    $this->capturedCommand = $cmd;

                    return " Tc\n";
                }

                return null;
            }
        };
        $probe->runProbe();

        $binary = InfocmpBinary::path();
        $this->assertNotNull($probe->capturedCommand);
        // The exec string must carry exactly what the availability gate
        // resolved — a bare relative `infocmp` would re-resolve through PATH
        // at exec time and let a planted entry answer the probe.
        if ($binary !== null) {
            $this->assertStringStartsWith($binary, $probe->capturedCommand);
        } else {
            // No infocmp on this box: the exec leg must not quietly fall back
            // to a PATH lookup either; the command must start at the (empty)
            // resolved path.
            $this->assertStringStartsWith(' -1 ', $probe->capturedCommand);
        }
        $this->assertDoesNotMatchRegularExpression('/^\s*infocmp\s/', $probe->capturedCommand);
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

    // ─── env suppression short-circuits the whole capability ladder ────

    /**
     * A terminfo blob that advertises both TrueColor and Sixel, with a public
     * call counter so the pins can prove the suppression gate never even
     * shells out to infocmp.
     */
    private function suppressedLadderProbe(array $env): TerminalProbe
    {
        return new class($env) extends TerminalProbe {
            public int $infocmpCalls = 0;

            protected function infocmpAvailable(): bool
            {
                return true;
            }

            protected function runCommand(string $cmd): ?string
            {
                if (str_contains($cmd, 'infocmp')) {
                    $this->infocmpCalls++;

                    return "\tTc,\n\tRGB,\n\tsixel,\n";
                }
                return null;
            }

            protected function isInteractive(): bool
            {
                return false;
            }
        };
    }

    public function testNoColorBeatsTrueColorTerminfo(): void
    {
        $probe = $this->suppressedLadderProbe(['NO_COLOR' => '1', 'TERM' => 'xterm-256color']);
        $report = $probe->runProbe();

        $this->assertSame(0, $probe->infocmpCalls, 'suppression must skip the terminfo phase entirely');
        $this->assertTrue($report->has(Capability::NoColor));
        $this->assertFalse($report->has(Capability::TrueColor));
        $this->assertFalse($report->has(Capability::Sixel));
    }

    public function testAnyNonEmptyNoColorValueSuppresses(): void
    {
        // NO_COLOR spec: the VALUE is irrelevant — any non-empty string disables.
        $probe = $this->suppressedLadderProbe(
            ['NO_COLOR' => 'please-no', 'TERM' => 'xterm-256color'],
        );
        $report = $probe->runProbe();

        $this->assertTrue($report->has(Capability::NoColor));
        $this->assertFalse($report->has(Capability::TrueColor));
    }

    public function testClicolorZeroBeatsTrueColorTerminfo(): void
    {
        $probe = $this->suppressedLadderProbe(['CLICOLOR' => '0', 'TERM' => 'xterm-256color']);
        $report = $probe->runProbe();

        $this->assertSame(0, $probe->infocmpCalls);
        $this->assertTrue($report->has(Capability::NoColor));
        $this->assertFalse($report->has(Capability::Sixel));
    }

    public function testClicolorForceOverridesNoColor(): void
    {
        // CLICOLOR_FORCE=1 is the operator's counter-override and must win.
        $probe = $this->suppressedLadderProbe(
            ['NO_COLOR' => '1', 'CLICOLOR_FORCE' => '1', 'TERM' => 'xterm-256color'],
        );
        $report = $probe->runProbe();

        $this->assertTrue($report->has(Capability::TrueColor));
        $this->assertSame('env:CLICOLOR_FORCE', $report->source(Capability::TrueColor));
        $this->assertFalse($report->has(Capability::NoColor));
    }

    public function testTermDumbSuppressesTerminfoLeg(): void
    {
        $probe = $this->suppressedLadderProbe(['TERM' => 'dumb']);
        $report = $probe->runProbe();

        $this->assertTrue($report->has(Capability::NoColor));
        $this->assertFalse($report->has(Capability::TrueColor));
        $this->assertFalse($report->has(Capability::Sixel));
    }

    public function testUnsuppressedEnvStillDetectsViaTerminfo(): void
    {
        // Regression guard: the gate only fires on suppression; normal
        // detection keeps its terminfo legs.
        $probe = $this->suppressedLadderProbe(['TERM' => 'xterm-256color']);
        $report = $probe->runProbe();

        $this->assertSame(1, $probe->infocmpCalls);
        $this->assertTrue($report->has(Capability::TrueColor));
        $this->assertSame('terminfo:Tc|RGB', $report->source(Capability::TrueColor));
        $this->assertTrue($report->has(Capability::Sixel));
    }

    public function testCheckTerminfoFailsClosedWhenTheRosterPathIsNull(): void
    {
        // m24: checkTerminfo composed the command with
        // `InfocmpBinary::path() ?? ''`. infocmpAvailable() is an
        // overridable seam, so a subclass (or a future internal change)
        // answering "yes" while the roster finds no binary left the
        // escaped TERM as the COMMAND WORD of " -1 'xterm' 2>/dev/null".
        // The leg now re-reads path() and a null fails closed: no command
        // at all, no terminfo-sourced capabilities.
        $probe = new class extends TerminalProbe {
            /** @var list<string> */
            public array $commands = [];

            public function __construct()
            {
                parent::__construct(['TERM' => 'xterm'], false);
            }

            protected function isInteractive(): bool
            {
                return false;
            }

            protected function infocmpAvailable(): bool
            {
                return true;
            }

            protected function runCommand(string $cmd): ?string
            {
                $this->commands[] = $cmd;

                return "\tTc,\n\trgb=\\E[38;2;%p1;%p2;%p3m,\n";
            }
        };

        InfocmpBinary::reset();
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, '');
        try {
            $report = $probe->runProbe();

            $this->assertSame([], $probe->commands, 'no command may be composed without an authoritative binary');
            $this->assertFalse($report->has(Capability::TrueColor), 'a null roster path must not source terminfo caps');
        } finally {
            InfocmpBinary::reset();
        }
    }

    public function testRepeatedRunsAnswerTheTerminfoLegFromTheSharedTable(): void
    {
        // 013 (CP-11): checkTerminfo re-execed infocmp on EVERY gated
        // runProbe — the docblocks encouraged cheap re-probing while the
        // base seam paid a fresh child each time. The bounded door memoizes
        // per TERM now; the fake counts its own launches in a marker file,
        // so the proof is bytes, not a reflection peek.
        $counter = (string) \tempnam(\sys_get_temp_dir(), 'cp-tp-count');
        $printer = (string) \tempnam(\sys_get_temp_dir(), 'infocmp');
        \file_put_contents(
            $printer,
            "#!/bin/sh\nprintf 'x\\n' >> " . \escapeshellarg($counter) . "\nprintf '\\tTc,\\n'\n",
        );
        \chmod($printer, 0755);

        InfocmpBinary::reset();
        (new \ReflectionProperty(InfocmpBinary::class, 'path'))->setValue(null, $printer);

        $probe = new class extends TerminalProbe {
            public function __construct()
            {
                parent::__construct(['TERM' => 'xterm'], false);
            }

            protected function isInteractive(): bool
            {
                return false;
            }

            protected function infocmpAvailable(): bool
            {
                return true;
            }
        };

        try {
            $first = $probe->runProbe();
            $second = $probe->runProbe();

            $this->assertTrue($first->has(Capability::TrueColor), 'the terminfo leg answers the first run');
            $this->assertTrue($second->has(Capability::TrueColor), 'and the second, from the shared table');
            $this->assertSame('terminfo:Tc|RGB', $second->source(Capability::TrueColor));
            $this->assertSame(
                1,
                \count(\file($counter)),
                'the child launched exactly once across two gated probes',
            );
        } finally {
            @\unlink($printer);
            @\unlink($counter);
            InfocmpBinary::reset();
        }
    }

    /**
     * CP-03 call-shape pin: the two environment parameters carry OPPOSITE
     * grammars — the constructor's `$environment` is a complete hermetic
     * replacement, `runProbe()`'s `$overrides` a name-only merge OVER the
     * instance map — and the names themselves are the documentation of the
     * split. Every in-repo caller is positional (grep-verified tree-wide),
     * so renaming is BC-safe while an arity change would not be: pin both
     * the names and the count.
     */
    public function testTheEnvironmentParametersAreNamedForTheirGrammar(): void
    {
        $names = static fn (\ReflectionMethod $m): array => \array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $m->getParameters(),
        );

        $this->assertSame(
            ['environment', 'interactive'],
            $names(new \ReflectionMethod(TerminalProbe::class, '__construct')),
        );
        $this->assertSame(
            ['overrides', 'interactive'],
            $names(new \ReflectionMethod(TerminalProbe::class, 'runProbe')),
        );
    }
}
