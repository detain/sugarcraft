<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\Waterfall;
use SugarCraft\Dash\Plot\Chart\WaterfallItem;

/**
 * E731 round-85: Waterfall carried no tests at all; this file pins the
 * border characters its former private copy of the style helper (now
 * shared through ChartBorderStyle) contributes to the rendered frame.
 */
final class WaterfallTest extends TestCase
{
    public function testWaterfallBorderCharacterArmsThroughChartBorderStyle(): void
    {
$items = [
            WaterfallItem::positive('start', 1000.0),
            WaterfallItem::negative('spend', -400.0),
            WaterfallItem::positive('gain', 250.0),
        ];
        $render = fn(string $style): string => Waterfall::new()->withItems($items)->withStyle($style)->render();

        $double = $render('double');
        $this->assertStringContainsString('╔', $double);
        $this->assertStringNotContainsString('╭', $double);
                $this->assertStringNotContainsString('┌', $double);

        $this->assertStringNotContainsString('┏', $double);

        $bold = $render('bold');
        $this->assertStringContainsString('┏', $bold);
        $this->assertStringNotContainsString('╔', $bold);
        $this->assertStringNotContainsString('╭', $bold);

        $single = $render('single');
                $this->assertStringContainsString('┌', $single);

        $this->assertStringNotContainsString('╭', $single);
        $this->assertStringNotContainsString('╔', $single);
        $this->assertStringNotContainsString('┏', $single);

        $rounded = $render('rounded');
        $this->assertStringContainsString('╭', $rounded);
        $this->assertStringNotContainsString('╔', $rounded);

        $empty = $render('empty');
        foreach (['╔', '╭', '┌',  '┏'] as $corner) {
            $this->assertStringNotContainsString($corner, $empty, "empty style must not draw $corner");
        }

        $this->assertSame($rounded, $render('bogus'), 'unknown style falls through to rounded');
    }
}
