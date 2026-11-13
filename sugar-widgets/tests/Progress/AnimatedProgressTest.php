<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Progress;

use SugarCraft\Widgets\Progress\AnimatedProgress;
use SugarCraft\Widgets\Progress\Progress;
use SugarCraft\Widgets\Progress\SpringTickMsg;
use SugarCraft\Core\TickRequest;
use PHPUnit\Framework\TestCase;

final class AnimatedProgressTest extends TestCase
{
    public function testStartsAtZeroAndNotAnimating(): void
    {
        $p = AnimatedProgress::new();
        $this->assertSame(0.0, $p->current);
        $this->assertSame(0.0, $p->target);
        $this->assertFalse($p->isAnimating());
    }

    public function testSetPercentSchedulesTickAndAnimates(): void
    {
        $p = AnimatedProgress::new()->withFps(60.0);
        [$next, $cmd] = $p->setPercent(0.5);
        $this->assertSame(0.5, $next->target);
        $this->assertTrue($next->isAnimating());
        $this->assertNotNull($cmd);
        $msg = $cmd();
        // The Cmd produces a TickRequest sentinel that the runtime
        // unpacks; the inner closure produces SpringTickMsg.
        $this->assertInstanceOf(TickRequest::class, $msg);
    }

    public function testSetPercentClampsToZeroOne(): void
    {
        $p = AnimatedProgress::new();
        [$over, ]  = $p->setPercent(1.5);
        [$under, ] = $p->setPercent(-0.5);
        $this->assertSame(1.0, $over->target);
        $this->assertSame(0.0, $under->target);
    }

    public function testTickAdvancesTowardTarget(): void
    {
        $p = AnimatedProgress::new()->withFps(60.0);
        [$p, ] = $p->setPercent(1.0);
        // Drive a few ticks and expect current to advance from 0 toward 1.
        for ($i = 0; $i < 5; $i++) {
            [$p, ] = $p->update(new SpringTickMsg($p->id, $p->epoch));
        }
        $this->assertGreaterThan(0.0, $p->current);
        $this->assertLessThanOrEqual(1.0, $p->current);
    }

    public function testIncrAndDecrAdjustTarget(): void
    {
        $p = AnimatedProgress::new();
        [$p, ] = $p->setPercent(0.5);
        [$incr, ] = $p->incrPercent(0.2);
        $this->assertEqualsWithDelta(0.7, $incr->target, 1e-9);
        [$decr, ] = $p->decrPercent(0.3);
        $this->assertEqualsWithDelta(0.2, $decr->target, 1e-9);
    }

    public function testJumpToSnapsAndStopsAnimating(): void
    {
        $p = AnimatedProgress::new();
        [$p, ] = $p->setPercent(1.0);
        $this->assertTrue($p->isAnimating());
        $p = $p->jumpTo(0.8);
        $this->assertSame(0.8, $p->current);
        $this->assertSame(0.8, $p->target);
        $this->assertFalse($p->isAnimating());
    }

    public function testTickIsNoOpWhenNotAnimating(): void
    {
        $p = AnimatedProgress::new();
        [$next, $cmd] = $p->update(new SpringTickMsg($p->id, $p->epoch));
        $this->assertSame($p, $next);
        $this->assertNull($cmd);
    }

    public function testEventuallySettlesWithinTolerance(): void
    {
        $p = AnimatedProgress::new()->withFps(60.0);
        [$p, ] = $p->setPercent(1.0);
        // Run a long sequence of ticks; the bar should settle.
        for ($i = 0; $i < 5000 && $p->isAnimating(); $i++) {
            [$p, ] = $p->update(new SpringTickMsg($p->id, $p->epoch));
        }
        $this->assertFalse($p->isAnimating());
        $this->assertEqualsWithDelta(1.0, $p->current, 0.001);
    }

    public function testViewRendersCurrentPercentNotTarget(): void
    {
        $p = AnimatedProgress::new()->withFps(60.0);
        [$p, ] = $p->setPercent(1.0);
        // After one tick current is non-zero but well below 1.0; view()
        // must reflect current, not target.
        [$p, ] = $p->update(new SpringTickMsg($p->id, $p->epoch));
        $out = $p->view();
        $this->assertStringContainsString('%', $out);
        // Target is 100%, current should be much less; verify NOT 100% yet.
        $this->assertStringNotContainsString('100%', $out);
    }

    public function testWithWidth(): void
    {
        $p = AnimatedProgress::new()->withWidth(60);
        $this->assertSame(60, $p->progress->width);
    }

    public function testWithRunes(): void
    {
        $p = AnimatedProgress::new()->withRunes('#', '.');
        $this->assertSame('#', $p->progress->fullChar);
        $this->assertSame('.', $p->progress->emptyChar);
    }

    public function testWithShowPercent(): void
    {
        $p = AnimatedProgress::new()->withShowPercent(false);
        $this->assertFalse($p->progress->showPercent);
    }

    public function testWithFillColor(): void
    {
        $c = \SugarCraft\Core\Util\Color::hex('#ff0000');
        $p = AnimatedProgress::new()->withFillColor($c);
        $this->assertSame($c, $p->progress->fillColor);
    }

    public function testWithEmptyColor(): void
    {
        $c = \SugarCraft\Core\Util\Color::hex('#0000ff');
        $p = AnimatedProgress::new()->withEmptyColor($c);
        $this->assertSame($c, $p->progress->emptyColor);
    }

    public function testWithColorProfile(): void
    {
        $p = AnimatedProgress::new()->withColorProfile(\SugarCraft\Core\Util\ColorProfile::Ansi256);
        $this->assertSame(\SugarCraft\Core\Util\ColorProfile::Ansi256, $p->progress->profile);
    }

    public function testWithGradient(): void
    {
        $a = \SugarCraft\Core\Util\Color::hex('#ff0000');
        $b = \SugarCraft\Core\Util\Color::hex('#0000ff');
        $p = AnimatedProgress::new()->withGradient($a, $b);
        $this->assertSame($a, $p->progress->gradientStart);
        $this->assertSame($b, $p->progress->gradientEnd);
    }

    public function testWithSolidFill(): void
    {
        $c = \SugarCraft\Core\Util\Color::hex('#00ff00');
        $p = AnimatedProgress::new()->withSolidFill($c);
        $this->assertSame($c, $p->progress->fillColor);
    }

    public function testWithDefaultGradient(): void
    {
        $p = AnimatedProgress::new()->withDefaultGradient();
        $this->assertNotNull($p->progress->gradientStart);
        $this->assertNotNull($p->progress->gradientEnd);
    }

    public function testWithPercentFormat(): void
    {
        $p = AnimatedProgress::new()->withPercentFormat('%5.1f%%');
        $this->assertSame('%5.1f%%', $p->progress->percentFormat);
    }

    public function testWithSpringOptions(): void
    {
        $p = AnimatedProgress::new()->withSpringOptions(10.0, 0.5);
        $this->assertSame(10.0, $p->angularFrequency);
        $this->assertSame(0.5, $p->dampingRatio);
    }

    public function testWithFps(): void
    {
        $p = AnimatedProgress::new()->withFps(30.0);
        $this->assertSame(30.0, $p->fps);
    }

    public function testInitReturnsNull(): void
    {
        $p = AnimatedProgress::new();
        $this->assertNull($p->init());
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $p = AnimatedProgress::new();
        $this->assertNull($p->subscriptions());
    }

    // ─── tick identity / clamp / single-clock (M5/M7/M40 legs) ──────

    public function testConcurrentBarsAdvanceOnlyTheirOwnChain(): void
    {
        [$a, ] = AnimatedProgress::new()->withFps(60.0)->setPercent(1.0);
        [$b, ] = AnimatedProgress::new()->withFps(60.0)->setPercent(1.0);
        $bBefore = $b;
        for ($i = 0; $i < 5; $i++) {
            [$a, ] = $a->update(new SpringTickMsg($a->id, $a->epoch));
        }
        $this->assertGreaterThan(0.0, $a->current, 'addressed chain advances');
        $this->assertSame(0.0, $bBefore->current);
        // b's snapshot never saw a tick addressed to it.
        [$untouched, $cmd] = $bBefore->update(new SpringTickMsg($a->id, $a->epoch));
        $this->assertSame($bBefore, $untouched, 'a-rate ticks must not move bar b');
        $this->assertNull($cmd);
        [$moved, ] = $bBefore->update(new SpringTickMsg($bBefore->id, $bBefore->epoch));
        $this->assertNotSame($bBefore, $moved, 'b moves on its own addressed tick');
    }

    public function testStaleEpochTickIsRefused(): void
    {
        [$p1, ] = AnimatedProgress::new()->setPercent(0.5);
        [$p2, ] = $p1->setPercent(0.8); // new generation bumps the epoch
        $this->assertSame($p1->epoch + 1, $p2->epoch);
        [$stale, $cmd] = $p2->update(new SpringTickMsg($p1->id, $p1->epoch));
        $this->assertSame($p2, $stale, 'in-flight tick from the previous generation is refused');
        $this->assertNull($cmd);
    }

    public function testNaNPercentClampsToEmptyBarNotFull(): void
    {
        [$p, ] = AnimatedProgress::new()->setPercent(NAN);
        $this->assertSame(0.0, $p->target);
        $p = $p->jumpTo(NAN);
        $this->assertSame(0.0, $p->current);
        $this->assertSame(AnimatedProgress::new()->view(), $p->view(), 'NaN reads as an empty bar');
    }

    public function testSubOneAndNaNFpsNeverFatalTheFirstTick(): void
    {
        // withFps(0.5): the single clock floors the rate at 1 — the old
        // (int) truncation handed Spring::fps(0) and died on first tick.
        [$p, ] = AnimatedProgress::new()->withFps(0.5)->setPercent(1.0);
        [$moved, $cmd] = $p->update(new SpringTickMsg($p->id, $p->epoch));
        $this->assertGreaterThan(0.0, $moved->current);
        $this->assertNotNull($cmd);
        // Public-ctor path with a NaN fps falls back to the default rate.
        $bar = new AnimatedProgress(
            progress: Progress::new(), current: 0.0, velocity: 0.0, target: 1.0,
            angularFrequency: 6.0, dampingRatio: 1.0, fps: NAN, animating: true,
        );
        [$moved, ] = $bar->update(new SpringTickMsg($bar->id, $bar->epoch));
        $this->assertGreaterThan(0.0, $moved->current);
    }
}
