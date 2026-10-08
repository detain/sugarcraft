<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;
use SugarCraft\Dash\Plot\Gradient101;

final class Gradient101Test extends TestCase
{
    public function testTwoStopRampUsesTruncatingIntegerLaw(): void
    {
        $ramp = Gradient101::expand([Color::rgb(0, 255, 255), Color::rgb(255, 0, 0)]);
        $this->assertCount(101, $ramp);
        $this->assertSame([0, 255, 255], [$ramp[0]->r, $ramp[0]->g, $ramp[0]->b]);
        // 255*50/100 = 127.5 → 127 up, and 255 - 127 = 128 down (not 127).
        $this->assertSame([127, 128, 128], [$ramp[50]->r, $ramp[50]->g, $ramp[50]->b]);
        $this->assertSame([255, 0, 0], [$ramp[100]->r, $ramp[100]->g, $ramp[100]->b]);
    }

    public function testThreeStopsSplitAtFifty(): void
    {
        $mid = Color::rgb(10, 20, 30);
        $ramp = Gradient101::expand([Color::rgb(0, 0, 0), $mid, Color::rgb(255, 255, 255)]);
        $this->assertSame([10, 20, 30], [$ramp[50]->r, $ramp[50]->g, $ramp[50]->b]);
    }

    public function testBrailleCanvasRampIsPinnedToBtopValues(): void
    {
        // BrailleCanvas delegates to expand(); pin the canvas path to
        // hand-computed btop entries so a divergence in either shows up.
        // Segment 0..50 from (3,200,17) to (250,9,140): i=25 → 3+25*247/50=126,
        // 200+25*-191/50=200-95=105 (truncates toward zero), 17+25*123/50=78.
        // Segment 50..100 to (1,1,255): i=75 → 250+25*-249/50=250-124=126,
        // 9+25*-8/50=5, 140+25*115/50=197.
        $stops = [Color::rgb(3, 200, 17), Color::rgb(250, 9, 140), Color::rgb(1, 1, 255)];
        $expected = [0 => [3, 200, 17], 25 => [126, 105, 78], 50 => [250, 9, 140], 75 => [126, 5, 197], 100 => [1, 1, 255]];
        $paths = [
            'expand' => Gradient101::expand($stops),
            'canvas' => BrailleCanvas::new(2, 4)->withGradient($stops)->gradient(),
        ];
        foreach ($paths as $name => $ramp) {
            $this->assertCount(101, $ramp, $name);
            foreach ($expected as $i => $rgb) {
                $this->assertSame($rgb, [$ramp[$i]->r, $ramp[$i]->g, $ramp[$i]->b], "{$name}[{$i}]");
            }
        }
    }

    public function testRejectsFewerThanTwoStops(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Gradient101::expand([Color::rgb(0, 0, 0)]);
    }

    public function testRejectsNonColorStop(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Gradient101::expand([Color::rgb(0, 0, 0), '#fff']);
    }
}
