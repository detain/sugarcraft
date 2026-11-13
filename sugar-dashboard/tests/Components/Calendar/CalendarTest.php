<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Calendar;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Calendar\Calendar;

final class CalendarTest extends TestCase
{
    public function testNowCreatesCurrentMonthCalendar(): void
    {
        $cal = Calendar::now();
        $this->assertNotNull($cal);
    }

    public function testOfCreatesSpecificMonthCalendar(): void
    {
        $cal = Calendar::of(2024, 6);
        $this->assertNotNull($cal);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $cal = Calendar::now();
        $this->assertNotSame('', $cal->render());
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $cal = Calendar::now();
        [$width, $height] = $cal->innerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithStartDayOfWeekReturnsNewInstance(): void
    {
        $cal = Calendar::now();
        $newCal = $cal->withStartDayOfWeek(1); // Monday
        $this->assertNotSame($cal, $newCal);
    }

    public function testWithHighlightDayReturnsNewInstance(): void
    {
        $cal = Calendar::now();
        $newCal = $cal->withHighlightDay(15);
        $this->assertNotSame($cal, $newCal);
    }

    public function testWithMarkedDaysReturnsNewInstance(): void
    {
        $cal = Calendar::now();
        $newCal = $cal->withMarkedDays([1, 15, 20]);
        $this->assertNotSame($cal, $newCal);
    }

    public function testWithHeaderColorReturnsNewInstance(): void
    {
        $cal = Calendar::now();
        $newCal = $cal->withHeaderColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertNotSame($cal, $newCal);
    }

    public function testWithMarkerColorReturnsNewInstance(): void
    {
        $cal = Calendar::now();
        $newCal = $cal->withMarkerColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertNotSame($cal, $newCal);
    }

    public function testMarkerColorIsAppliedToMarkedDays(): void
    {
        // Finding sugar-dashboard-163: markerColor was ctor-only while the
        // render site already consults it — the wither now exists and this
        // pins that it actually reaches the marker glyph.
        $cal = Calendar::of(2024, 6)->withMarkedDays([3]);
        $fg = \SugarCraft\Core\Util\Color::hex('#FF0000')->toFg(\SugarCraft\Dashboard\Foundation\ColorMode::profile());
        $colored = $cal->withMarkerColor(\SugarCraft\Core\Util\Color::hex('#FF0000'))->render();
        $this->assertStringContainsString($fg . '* 3', $colored);
        $this->assertStringNotContainsString($fg, $cal->render());
    }

    public function testRenderContainsMonthName(): void
    {
        $cal = Calendar::of(2024, 6);
        $rendered = $cal->render();
        $this->assertStringContainsString('June', $rendered);
        $this->assertStringContainsString('2024', $rendered);
    }

    public function testRenderContainsDayNames(): void
    {
        $cal = Calendar::now();
        $rendered = $cal->render();
        // Should contain day abbreviations
        $this->assertStringContainsString('Su', $rendered);
        $this->assertStringContainsString('Mo', $rendered);
    }
}
