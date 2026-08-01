<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Progress;

use SugarCraft\Bits\Progress\AnimatedProgress;
use SugarCraft\Bits\Progress\SpringTickMsg;
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
            [$p, ] = $p->update(new SpringTickMsg());
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
        [$next, $cmd] = $p->update(new SpringTickMsg());
        $this->assertSame($p, $next);
        $this->assertNull($cmd);
    }

    public function testEventuallySettlesWithinTolerance(): void
    {
        $p = AnimatedProgress::new()->withFps(60.0);
        [$p, ] = $p->setPercent(1.0);
        // Run a long sequence of ticks; the bar should settle.
        for ($i = 0; $i < 5000 && $p->isAnimating(); $i++) {
            [$p, ] = $p->update(new SpringTickMsg());
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
        [$p, ] = $p->update(new SpringTickMsg());
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
}
