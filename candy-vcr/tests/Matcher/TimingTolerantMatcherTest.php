<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Matcher;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Matcher\TimingTolerantMatcher;

final class TimingTolerantMatcherTest extends TestCase
{
    public function testDefaultToleranceIs100Milliseconds(): void
    {
        $matcher = new TimingTolerantMatcher();

        $recorded = new Event(1.0, EventKind::Output, ['b' => 'x']);
        $actual = new Event(1.05, EventKind::Output, ['b' => 'x']);

        $this->assertTrue($matcher->matches($recorded, $actual));
    }

    public function testWithinToleranceReturnsTrue(): void
    {
        $matcher = new TimingTolerantMatcher(timingTolerance: 0.25);

        $recorded = new Event(1.0, EventKind::Output, ['b' => 'x']);
        $actual = new Event(1.2, EventKind::Output, ['b' => 'x']);

        $this->assertTrue($matcher->matches($recorded, $actual));
    }

    public function testOutsideToleranceReturnsFalse(): void
    {
        $matcher = new TimingTolerantMatcher(timingTolerance: 0.1);

        $recorded = new Event(1.0, EventKind::Output, ['b' => 'x']);
        $actual = new Event(1.2, EventKind::Output, ['b' => 'x']);

        $this->assertFalse($matcher->matches($recorded, $actual));
    }

    public function testExactTimestampMatches(): void
    {
        $matcher = new TimingTolerantMatcher(timingTolerance: 0.0);

        $recorded = new Event(1.5, EventKind::Output, ['b' => 'x']);
        $actual = new Event(1.5, EventKind::Output, ['b' => 'x']);

        $this->assertTrue($matcher->matches($recorded, $actual));
    }

    public function testZeroToleranceRejectsDifferentTimestamps(): void
    {
        $matcher = new TimingTolerantMatcher(timingTolerance: 0.0);

        $recorded = new Event(1.5, EventKind::Output, ['b' => 'x']);
        $actual = new Event(1.51, EventKind::Output, ['b' => 'x']);

        $this->assertFalse($matcher->matches($recorded, $actual));
    }

    public function testDifferentKindReturnsFalseRegardlessOfTiming(): void
    {
        $matcher = new TimingTolerantMatcher(timingTolerance: 10.0);

        $recorded = new Event(1.0, EventKind::Input, ['b' => 'a']);
        $actual = new Event(1.0, EventKind::Output, ['b' => 'a']);

        $this->assertFalse($matcher->matches($recorded, $actual));
    }

    public function testNegativeTimestampNotAllowed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TimingTolerantMatcher(timingTolerance: -0.1);
    }

    public function testZeroToleranceIsValid(): void
    {
        $matcher = new TimingTolerantMatcher(timingTolerance: 0.0);
        $this->assertInstanceOf(TimingTolerantMatcher::class, $matcher);
    }

    public function testPayloadDifferenceIgnoredWithinTimingTolerance(): void
    {
        $matcher = new TimingTolerantMatcher(timingTolerance: 0.1);

        $recorded = new Event(1.0, EventKind::Resize, ['cols' => 80, 'rows' => 24]);
        $actual = new Event(1.05, EventKind::Resize, ['cols' => 200, 'rows' => 80]);

        $this->assertTrue($matcher->matches($recorded, $actual));
    }

    public function testActualEarlierThanRecordedWithinTolerance(): void
    {
        $matcher = new TimingTolerantMatcher(timingTolerance: 0.05);

        $recorded = new Event(1.0, EventKind::Output, ['b' => 'x']);
        $actual = new Event(0.96, EventKind::Output, ['b' => 'x']);

        $this->assertTrue($matcher->matches($recorded, $actual));
    }

    public function testActualMuchEarlierOutsideTolerance(): void
    {
        $matcher = new TimingTolerantMatcher(timingTolerance: 0.05);

        $recorded = new Event(1.0, EventKind::Output, ['b' => 'x']);
        $actual = new Event(0.9, EventKind::Output, ['b' => 'x']);

        $this->assertFalse($matcher->matches($recorded, $actual));
    }
}
