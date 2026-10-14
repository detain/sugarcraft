<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Flip\ArtLoop;

final class ArtLoopTest extends TestCase
{
    /** Binary-exact durations keep boundary assertions free of float slop. */
    private const FRAMES = 4;

    private const FRAME_SECONDS = 0.25;

    private const PAUSE_SECONDS = 1.0;

    public function testNewExposesConstructionViaReadonlyProps(): void
    {
        $loop = ArtLoop::new(self::FRAMES, self::FRAME_SECONDS, self::PAUSE_SECONDS);
        self::assertSame(self::FRAMES, $loop->frameCount);
        self::assertSame(self::FRAME_SECONDS, $loop->frameSeconds);
        self::assertSame(self::PAUSE_SECONDS, $loop->pauseSeconds);
    }

    public function testDefaultsAreTheHousePlaybackFeel(): void
    {
        $loop = ArtLoop::new(10);
        self::assertSame(0.1, $loop->frameSeconds);
        self::assertSame(2.5, $loop->pauseSeconds);
    }

    public function testCycleSecondsHoldsLastFrameThenRestarts(): void
    {
        $loop = ArtLoop::new(self::FRAMES, self::FRAME_SECONDS, self::PAUSE_SECONDS);
        // 4 frames x 0.25 + 1.0 pause = 2.0
        self::assertSame(2.0, $loop->cycleSeconds());
    }

    public function testTimeZeroShowsFirstFrame(): void
    {
        self::assertSame(0, ArtLoop::new(self::FRAMES, self::FRAME_SECONDS, self::PAUSE_SECONDS)->frameAt(0.0));
    }

    public function testFrameBoundariesLandOnLaterFrame(): void
    {
        $loop = ArtLoop::new(self::FRAMES, self::FRAME_SECONDS, self::PAUSE_SECONDS);
        self::assertSame(0, $loop->frameAt(0.24));
        self::assertSame(1, $loop->frameAt(0.25), 'exact boundary belongs to the next frame');
        self::assertSame(2, $loop->frameAt(0.6));
        self::assertSame(3, $loop->frameAt(0.75), 'start of the last frame');
    }

    public function testLastFrameHoldsAcrossPauseWindow(): void
    {
        $loop = ArtLoop::new(self::FRAMES, self::FRAME_SECONDS, self::PAUSE_SECONDS);
        self::assertSame(3, $loop->frameAt(0.99));
        self::assertSame(3, $loop->frameAt(1.5), 'mid-pause still shows the last frame');
        self::assertSame(3, $loop->frameAt(1.9999));
    }

    public function testCycleWrapsBackToFirstFrame(): void
    {
        $loop = ArtLoop::new(self::FRAMES, self::FRAME_SECONDS, self::PAUSE_SECONDS);
        self::assertSame(0, $loop->frameAt(2.0), 'exactly one cycle restarts');
        self::assertSame(1, $loop->frameAt(2.25));
        self::assertSame(3, $loop->frameAt(3.9));
        self::assertSame(0, $loop->frameAt(4.0), 'two cycles later');
    }

    public function testSingleFrameLoopAlwaysShowsFrameZero(): void
    {
        $loop = ArtLoop::new(1, 0.5, 1.0);
        self::assertSame(0, $loop->frameAt(0.0));
        self::assertSame(0, $loop->frameAt(0.4));
        self::assertSame(0, $loop->frameAt(0.5), 'inside the pause hold');
        self::assertSame(0, $loop->frameAt(123.4));
    }

    public function testZeroFrameSecondsPinsToLastFrameWithoutDivideByZero(): void
    {
        $loop = ArtLoop::new(5, 0.0, 1.0);
        self::assertSame(4, $loop->frameAt(0.0), 'every position falls in the last-frame window');
        self::assertSame(4, $loop->frameAt(0.99));
    }

    public function testFrameAtRejectsNegativeElapsed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(2)->frameAt(-0.001);
    }

    public function testFrameAtRejectsInfiniteElapsed(): void
    {
        // fmod(INF, cycle) is NAN and (int) NAN reads 0 — a non-finite clock
        // offset must fail loud, not silently pin frame 0.
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(2)->frameAt(INF);
    }

    public function testFrameAtRejectsNanElapsed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(2)->frameAt(NAN);
    }

    public function testRejectsZeroAndNegativeFrameCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(0);
    }

    public function testRejectsNegativeFrameCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(-3);
    }

    public function testRejectsNegativeDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(2, -0.1);
    }

    public function testRejectsNegativePause(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(2, 0.1, -0.1);
    }

    public function testRejectsNonFiniteDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(2, NAN);
    }

    public function testRejectsInfiniteDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(2, INF);
    }

    public function testRejectsFullyFrozenCycle(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(4, 0.0, 0.0);
    }

    public function testWithPauseIsFluentAndLeavesOriginalIntact(): void
    {
        $loop = ArtLoop::new(self::FRAMES, self::FRAME_SECONDS, self::PAUSE_SECONDS);
        $held = $loop->withPause(5.0);

        self::assertNotSame($loop, $held);
        self::assertSame(5.0, $held->pauseSeconds);
        self::assertSame(1.0, $loop->pauseSeconds, 'original unchanged');
        self::assertSame(self::FRAMES, $held->frameCount);
        self::assertSame(self::FRAME_SECONDS, $held->frameSeconds);
        self::assertSame(6.0, $held->cycleSeconds());
    }

    public function testWithPauseValidatesLikeTheFactory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ArtLoop::new(2)->withPause(-1.0);
    }

    public function testWithPauseMayRemoveTheHoldEntirely(): void
    {
        $loop = ArtLoop::new(2, 0.5, 3.0)->withPause(0.0);
        self::assertSame(1.0, $loop->cycleSeconds());
        self::assertSame(1, $loop->frameAt(0.75));
        self::assertSame(0, $loop->frameAt(1.0));
    }
}
