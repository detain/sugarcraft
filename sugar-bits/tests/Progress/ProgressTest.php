<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\Progress;

use CandyCore\Bits\Progress\Progress;
use CandyCore\Core\Util\Color;
use CandyCore\Core\Util\ColorProfile;
use CandyCore\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class ProgressTest extends TestCase
{
    public function testZeroPercent(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.');
        $this->assertSame(str_repeat('.', 10), $p->withPercent(0.0)->view());
    }

    public function testFullPercent(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.');
        $this->assertSame(str_repeat('#', 10), $p->withPercent(1.0)->view());
    }

    public function testHalfPercent(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.');
        $this->assertSame('#####.....', $p->withPercent(0.5)->view());
    }

    public function testPercentClampedToZeroOne(): void
    {
        $p = Progress::new()->withWidth(10)->withShowPercent(false)->withRunes('#', '.');
        $this->assertSame(str_repeat('#', 10), $p->withPercent(2.0)->view());
        $this->assertSame(str_repeat('.', 10), $p->withPercent(-0.5)->view());
    }

    public function testWithPercentSuffix(): void
    {
        $p = Progress::new()->withWidth(10)->withRunes('#', '.')->withPercent(0.5);
        // 10 - 5 (" 100%") = 5 cells for bar; round(0.5*5) = 3 filled, 2 empty.
        $this->assertSame('###..  50%', $p->view());
    }

    public function testHundredPercentSuffix(): void
    {
        $p = Progress::new()->withWidth(10)->withRunes('#', '.')->withPercent(1.0);
        $this->assertSame(str_repeat('#', 5) . ' 100%', $p->view());
    }

    public function testFillColorWraps(): void
    {
        $p = Progress::new()
            ->withWidth(5)
            ->withShowPercent(false)
            ->withRunes('#', '.')
            ->withFillColor(Color::hex('#ff0000'))
            ->withColorProfile(ColorProfile::TrueColor)
            ->withPercent(1.0);
        $this->assertSame("\x1b[38;2;255;0;0m" . str_repeat('#', 5) . "\x1b[0m", $p->view());
    }

    public function testNegativeWidthRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Progress(width: -1);
    }

    public function testViewWidthMatchesConfiguredWidth(): void
    {
        $p = Progress::new()->withWidth(20)->withPercent(0.5);
        $this->assertSame(20, $p->viewWidth());
    }
}
