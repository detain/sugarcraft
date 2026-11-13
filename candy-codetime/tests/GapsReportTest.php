<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeTime\Heartbeat;
use SugarCraft\CodeTime\Report\GapsReport;

final class GapsReportTest extends TestCase
{
    public function testEmptyHeartbeatsReturnsNoGaps(): void
    {
        $report = new GapsReport([]);
        $this->assertSame([], $report->gaps());
        $this->assertSame(0, $report->totalUntrackedSeconds());
    }

    public function testSingleHeartbeatReturnsNoGaps(): void
    {
        $hb = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $report = new GapsReport([$hb]);
        $this->assertSame([], $report->gaps());
        $this->assertSame(0, $report->totalUntrackedSeconds());
    }

    public function testContiguousHeartbeatsNoGap(): void
    {
        $hb1 = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $hb2 = new Heartbeat(time: 1700000060, project: 'demo', language: 'php', file: 'b.php', duration: 60);
        $report = new GapsReport([$hb1, $hb2]);
        $this->assertSame([], $report->gaps());
        $this->assertSame(0, $report->totalUntrackedSeconds());
    }

    public function testSmallGapBelowThresholdNotReported(): void
    {
        $hb1 = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $hb2 = new Heartbeat(time: 1700000100, project: 'demo', language: 'php', file: 'b.php', duration: 60);
        $report = new GapsReport([$hb1, $hb2], minGapSeconds: 300);
        $this->assertSame([], $report->gaps());
    }

    public function testLargeGapDetected(): void
    {
        $hb1 = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $hb2 = new Heartbeat(time: 1700001000, project: 'demo', language: 'php', file: 'b.php', duration: 60);
        $report = new GapsReport([$hb1, $hb2], minGapSeconds: 300);
        $gaps = $report->gaps();
        $this->assertCount(1, $gaps);
        $this->assertSame(1700000060, $gaps[0]['start']);
        $this->assertSame(1700001000, $gaps[0]['end']);
        $this->assertSame(940, $gaps[0]['gapSeconds']);
    }

    public function testTotalUntrackedSeconds(): void
    {
        $hb1 = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $hb2 = new Heartbeat(time: 1700001000, project: 'demo', language: 'php', file: 'b.php', duration: 60);
        $report = new GapsReport([$hb1, $hb2], minGapSeconds: 300);
        $this->assertSame(940, $report->totalUntrackedSeconds());
    }

    public function testMultipleGapsDetected(): void
    {
        $hb1 = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $hb2 = new Heartbeat(time: 1700001000, project: 'demo', language: 'php', file: 'b.php', duration: 60);
        $hb3 = new Heartbeat(time: 1700002000, project: 'demo', language: 'php', file: 'c.php', duration: 60);
        $report = new GapsReport([$hb1, $hb2, $hb3], minGapSeconds: 300);
        $gaps = $report->gaps();
        $this->assertCount(2, $gaps);
        $this->assertSame(940, $gaps[0]['gapSeconds']);
        $this->assertSame(940, $gaps[1]['gapSeconds']);
        $this->assertSame(1880, $report->totalUntrackedSeconds());
    }

    public function testUnsortedHeartbeatsAreSorted(): void
    {
        $hb1 = new Heartbeat(time: 1700001000, project: 'demo', language: 'php', file: 'b.php', duration: 60);
        $hb2 = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $report = new GapsReport([$hb1, $hb2], minGapSeconds: 300);
        $gaps = $report->gaps();
        $this->assertCount(1, $gaps);
        $this->assertSame(940, $gaps[0]['gapSeconds']);
    }

    public function testCustomMinGapThreshold(): void
    {
        $hb1 = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $hb2 = new Heartbeat(time: 1700000100, project: 'demo', language: 'php', file: 'b.php', duration: 60);
        $report = new GapsReport([$hb1, $hb2], minGapSeconds: 10);
        $gaps = $report->gaps();
        $this->assertCount(1, $gaps);
        $this->assertSame(40, $gaps[0]['gapSeconds']);
    }

    // CT-S43 (finding 047): the pair-wise walk reported the shadow of an
    // open window as untracked time — a 60 s attribution beat landing
    // inside a long one invented a gap across the still-covered stretch.
    public function testAnAttributionBeatInsideAnOpenWindowInventsNoGap(): void
    {
        $a = new Heartbeat(time: 0, project: 'demo', language: 'php', file: 'a.php', duration: 10000);
        $b = new Heartbeat(time: 100, project: 'demo', language: 'php', file: 'b.php', duration: 60);
        $c = new Heartbeat(time: 9000, project: 'demo', language: 'php', file: 'c.php', duration: 60);
        $report = new GapsReport([$a, $b, $c], minGapSeconds: 300);
        $this->assertSame([], $report->gaps());

        // The horizon keeps running: once every window closes, the next
        // beat opens the real gap at the far edge of the longest window.
        $d = new Heartbeat(time: 20000, project: 'demo', language: 'php', file: 'd.php', duration: 60);
        $report = new GapsReport([$a, $b, $c, $d], minGapSeconds: 300);
        $gaps = $report->gaps();
        $this->assertCount(1, $gaps);
        $this->assertSame(10000, $gaps[0]['start'], 'the far edge of the LONGEST window, not the last beat');
        $this->assertSame(20000, $gaps[0]['end']);
        $this->assertSame(10000, $gaps[0]['gapSeconds']);
    }

    // CT-S43 (finding 067): the threshold is now reachable; the 300 s
    // default keeps answering when --min is not given.
    public function testTheThresholdIsAKnobNotAFrozenConstant(): void
    {
        $a = new Heartbeat(time: 1700000000, project: 'demo', language: 'php', file: 'a.php', duration: 60);
        $b = new Heartbeat(time: 1700000560, project: 'demo', language: 'php', file: 'b.php', duration: 60);
        $this->assertCount(1, (new GapsReport([$a, $b], minGapSeconds: 300))->gaps());
        $this->assertCount(1, (new GapsReport([$a, $b], minGapSeconds: 500))->gaps());
        $this->assertSame([], (new GapsReport([$a, $b], minGapSeconds: 501))->gaps());
        $this->assertSame([], (new GapsReport([$a, $b], minGapSeconds: 900))->gaps());
    }
}
