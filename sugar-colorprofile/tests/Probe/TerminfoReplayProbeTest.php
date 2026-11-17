<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Tests\Probe;

use PHPUnit\Framework\TestCase;
use SugarCraft\ColorProfile\Probe\Capability;
use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\TerminfoReplayProbe;

/**
 * @covers \SugarCraft\ColorProfile\Probe\TerminfoReplayProbe
 *
 * The two passes behind AsyncProbe::report(), pinned hermetically through the
 * complete-replacement environment: the recording pass must ask for a dump
 * exactly when the sync pipeline would reach its terminfo leg, and the
 * replaying pass must compose the given dump at that same leg.
 */
final class TerminfoReplayProbeTest extends TestCase
{
    public function testRecordingPassAsksForTheTermWhenTheLegIsReached(): void
    {
        if (InfocmpBinary::path() === null) {
            $this->markTestSkipped('no infocmp binary on this host');
        }

        $probe = TerminfoReplayProbe::recording(['TERM' => 'xterm-256color']);
        $probe->runProbe();

        $this->assertSame('xterm-256color', $probe->requestedTerm());
    }

    public function testRecordingPassStaysSilentWhenSuppressionAnswersFirst(): void
    {
        $probe = TerminfoReplayProbe::recording(['TERM' => 'xterm-256color', 'NO_COLOR' => '1']);
        $report = $probe->runProbe();

        $this->assertNull($probe->requestedTerm(), 'NO_COLOR terminates the ladder before the terminfo leg');
        $this->assertTrue($report->has(Capability::NoColor));
    }

    public function testRecordingPassStaysSilentForTermDumb(): void
    {
        $probe = TerminfoReplayProbe::recording(['TERM' => 'dumb']);
        $probe->runProbe();

        $this->assertNull($probe->requestedTerm(), 'TERM=dumb never reaches infocmp');
    }

    public function testReplayingPassComposesTheDumpAtTheTerminfoSeam(): void
    {
        if (InfocmpBinary::path() === null) {
            $this->markTestSkipped('no infocmp binary on this host');
        }

        $report = TerminfoReplayProbe::replaying(" Tc RGB sgr0 sitm ritm\n", ['TERM' => 'xterm-256color'])->runProbe();

        $this->assertTrue($report->has(Capability::TrueColor));
        $this->assertSame('terminfo:Tc|RGB', $report->source(Capability::TrueColor));
    }

    public function testReplayingPassWithNoDumpFailsOpenToTheEnvironmentAnswer(): void
    {
        $report = TerminfoReplayProbe::replaying(null, ['TERM' => 'xterm-256color'])->runProbe();

        $this->assertFalse($report->has(Capability::TrueColor), 'a timed-out or killed child proves nothing');
        $this->assertTrue($report->has(Capability::BasicAscii), 'the floor always stands');
    }
}
