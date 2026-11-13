<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Renderer;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\Mark;

/** fitStatusBar() re-applies zone marks after the per-segment cut (S-O24). */
final class StatusBarCrowdedMenuZoneTest extends TestCase
{
    private function fit(string $bar, int $cols): string
    {
        return (new \ReflectionMethod(Renderer::class, 'fitStatusBar'))->invoke(null, $bar, $cols);
    }

    private function strip(string $s): string
    {
        return (string) (new \ReflectionMethod(Renderer::class, 'stripZoneMarkers'))->invoke(null, $s);
    }

    public function testFittingBarIsReturnedByteIdentical(): void
    {
        $bar = '12% · ' . Mark::zone('pane:menu', '^P menu');
        $this->assertSame($bar, $this->fit($bar, 80));
    }

    public function testCrowdedBarKeepsSurvivingMenuZone(): void
    {
        $bar = 'ctx 12% of 200K · $0.42 of $5.00 cap · ' . Mark::zone('pane:menu', '^P menu');
        $out = $this->fit($bar, 56);

        $this->assertSame(Width::truncate($this->strip($bar), 56), $this->strip($out));
        $this->assertStringContainsString(Mark::zone('pane:menu', '^P menu'), $out);
    }

    public function testZoneCutToNothingIsDroppedNotHalved(): void
    {
        $bar = 'abcdefghij · ' . Mark::zone('pane:menu', 'menu');
        $out = $this->fit($bar, 5);

        $this->assertSame('abcde', $out);
    }

    public function testPartiallySurvivingZoneIsStillWellFormed(): void
    {
        $bar = 'ab ' . Mark::zone('pane:menu', 'menu');
        $out = $this->fit($bar, 5);

        $this->assertSame('ab ' . Mark::zone('pane:menu', 'me'), $out);
    }
}
