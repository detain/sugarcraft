<?php

declare(strict_types=1);

namespace SugarCraft\Tick\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Tick\Heartbeat;
use SugarCraft\Tick\Report\GapsReport;

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
}
