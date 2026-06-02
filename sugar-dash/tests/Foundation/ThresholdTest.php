<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Foundation;

use SugarCraft\Core\Util\Color;
use SugarCraft\Dash\Foundation\Threshold;
use PHPUnit\Framework\TestCase;

final class ThresholdTest extends TestCase
{
    public function testHealthRampGreenBelowSixty(): void
    {
        $t = Threshold::health();
        $this->assertSame('#4ade80', $t->colorFor(0.0)->toHex());
        $this->assertSame('#4ade80', $t->colorFor(0.3)->toHex());
        $this->assertSame('#4ade80', $t->colorFor(0.59)->toHex());
    }

    public function testHealthRampYellowBetweenSixtyAndEighty(): void
    {
        $t = Threshold::health();
        $this->assertSame('#facc15', $t->colorFor(0.6)->toHex());
        $this->assertSame('#facc15', $t->colorFor(0.7)->toHex());
        $this->assertSame('#facc15', $t->colorFor(0.79)->toHex());
    }

    public function testHealthRampRedAtOrAboveEighty(): void
    {
        $t = Threshold::health();
        $this->assertSame('#f87171', $t->colorFor(0.8)->toHex());
        $this->assertSame('#f87171', $t->colorFor(1.0)->toHex());
        $this->assertSame('#f87171', $t->colorFor(5.0)->toHex());
    }

    public function testHealthColorsAreOverridable(): void
    {
        $t = Threshold::health(Color::hex('#000000'), Color::hex('#111111'), Color::hex('#222222'));
        $this->assertSame('#000000', $t->colorFor(0.1)->toHex());
        $this->assertSame('#111111', $t->colorFor(0.7)->toHex());
        $this->assertSame('#222222', $t->colorFor(0.9)->toHex());
    }

    public function testOfSortsStopsAscending(): void
    {
        // Provided out of order; resolution must still respect ascending limits.
        $t = Threshold::of([
            [1.0, Color::hex('#ff0000')],
            [0.5, Color::hex('#00ff00')],
        ]);
        $this->assertSame('#00ff00', $t->colorFor(0.2)->toHex());
        $this->assertSame('#ff0000', $t->colorFor(0.7)->toHex());
        // At/above the top finite limit, take the top color.
        $this->assertSame('#ff0000', $t->colorFor(2.0)->toHex());
    }

    public function testStopsAccessor(): void
    {
        $t = Threshold::of([[0.5, Color::hex('#abcdef')]]);
        $stops = $t->stops();
        $this->assertCount(1, $stops);
        $this->assertSame(0.5, $stops[0]['limit']);
    }
}
