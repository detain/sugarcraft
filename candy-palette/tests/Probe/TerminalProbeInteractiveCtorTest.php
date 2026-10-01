<?php

declare(strict_types=1);

namespace SugarCraft\Palette\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\Palette\Probe\Capability;
use SugarCraft\Palette\Probe\TerminalProbe;

/**
 * Pins the two environment-semantics fixes in TerminalProbe (audit #5, #9).
 *
 * #5: the constructor's $interactive decision was clobbered by runProbe()'s
 * old `bool $interactive = true` default — passing false to the constructor
 * had no effect once runProbe() re-evaluated. Now null means "honor the
 * constructor", true re-evaluates, false forces off.
 *
 * #9: set-but-empty session markers (WT_SESSION='', TMUX='') are NOT
 * evidence of a session — only non-empty values advertise.
 */
final class TerminalProbeInteractiveCtorTest extends TestCase
{
    private function probing(bool $interactive, array $env): TerminalProbe
    {
        return new class($env, $interactive) extends TerminalProbe {
            protected function isInteractive(): bool
            {
                return true;
            }
        };
    }

    public function testConstructorNonInteractiveIsHonoredByBareRunProbe(): void
    {
        $probe = $this->probing(false, ['TERM_PROGRAM' => 'iTerm.app', 'TERM' => 'xterm']);
        $report = $probe->runProbe();

        $this->assertFalse(
            $report->has(Capability::ITerm2),
            'Phase 3 must stay off when the constructor said non-interactive (audit #5)'
        );
    }

    public function testConstructorInteractiveIsHonoredByBareRunProbe(): void
    {
        $probe = $this->probing(true, ['TERM_PROGRAM' => 'iTerm.app', 'TERM' => 'xterm']);
        $report = $probe->runProbe();

        $this->assertTrue($report->has(Capability::ITerm2));
        $this->assertStringStartsWith('env:TERM_PROGRAM=', $report->source(Capability::ITerm2));
    }

    public function testExplicitTrueAtRunProbeStillReEvaluates(): void
    {
        $probe = $this->probing(false, ['TERM_PROGRAM' => 'iTerm.app', 'TERM' => 'xterm']);
        $report = $probe->runProbe([], true);

        $this->assertTrue(
            $report->has(Capability::ITerm2),
            'an explicit true at the call site overrides the constructor and re-consults isInteractive()'
        );
    }

    public function testExplicitFalseAtRunProbeForcesPhaseThreeOff(): void
    {
        $probe = $this->probing(true, ['TERM_PROGRAM' => 'iTerm.app', 'TERM' => 'xterm']);
        $report = $probe->runProbe([], false);

        $this->assertFalse($report->has(Capability::ITerm2));
    }

    public function testEmptyWtSessionDoesNotAdvertiseTrueColor(): void
    {
        $probe = $this->probing(false, [
            'WT_SESSION' => '',
            'TERM' => 'xterm',
            'TMUX' => null,
            'STY' => null,
            'COLORTERM' => null,
            'CLICOLOR_FORCE' => null,
        ]);
        $report = $probe->runProbe();

        $this->assertFalse(
            $report->has(Capability::TrueColor),
            'WT_SESSION set but empty is not a Windows Terminal session (audit #9)'
        );
    }

    public function testNonEmptyWtSessionAdvertisesTrueColor(): void
    {
        $probe = $this->probing(false, [
            'WT_SESSION' => 'c9e5f4a2-0b6d-4a48-9b5e-1f2d3c4b5a69',
            'TERM' => 'xterm',
            'TMUX' => null,
            'STY' => null,
        ]);
        $report = $probe->runProbe();

        $this->assertTrue($report->has(Capability::TrueColor));
        $this->assertSame('env:WT_SESSION', $report->source(Capability::TrueColor));
    }

    public function testEmptyTmuxDoesNotAdvertiseColor256ViaSessionMarker(): void
    {
        $probe = $this->probing(false, [
            'TMUX' => '',
            'STY' => null,
            'TERM' => 'screen',
            'WT_SESSION' => null,
            'COLORTERM' => null,
            'CLICOLOR_FORCE' => null,
        ]);
        $report = $probe->runProbe();

        $source = $report->source(Capability::Color256);

        $this->assertTrue(
            $source === null || !str_starts_with((string) $source, 'env:TMUX|STY+'),
            'empty TMUX must not take the env:TMUX|STY+ branch (audit #9)'
        );
    }

    public function testNonEmptyTmuxWithScreenTermAdvertisesColor256(): void
    {
        $probe = $this->probing(false, [
            'TMUX' => '/tmp/tmux-1000/default,1234,0',
            'STY' => null,
            'TERM' => 'screen',
            'WT_SESSION' => null,
        ]);
        $report = $probe->runProbe();

        $this->assertSame('env:TMUX|STY+TMUX', $report->source(Capability::Color256));
    }
}
