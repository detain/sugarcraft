<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Style\Theme;

/**
 * CH-20 (sugar-charts-085/-057/-058): LineChart API hygiene — private
 * constructor (covered by LineChartBatch2Test door migration), nullable
 * short aliases clearing their knob, the negative-duration numeric door,
 * and the narrowed styleForColor catch.
 */
final class LineChartApiHygieneTest extends TestCase
{
    public function testConstructorIsPrivateHouseLaw(): void
    {
        // sugar-charts-091: exactly one public ingress (::new()); static
        // analysis refuses `new LineChart(...)` once this holds, and the
        // reflection pin makes a re-widening go red in the suite.
        $ctor = new \ReflectionMethod(LineChart::class, '__construct');
        $this->assertTrue($ctor->isPrivate(), 'LineChart::__construct must stay private (::new() is the documented ingress)');
    }

    public function testNewIsTheSolePublicConstructorSurface(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/LineChart/LineChart.php');
        // No `new LineChart(` construction anywhere in src either (withers
        // rebuild through new self()/private ctor paths, not by name).
        $this->assertStringNotContainsString('new LineChart(', $src);
    }

    public function testDataLabelFormatAliasClearsKnobWithNull(): void
    {
        $fn = static fn(): string => 'x';
        $set = LineChart::new()->dataLabelFormat($fn);
        $this->assertSame($fn, $set->dataLabelFormatter, 'alias must reach the same storage as withDataLabelFormatter()');

        // Explicit null and the bare no-arg form both clear — the short
        // chain can now do what the wither always could (sugar-charts-085).
        $this->assertNull($set->dataLabelFormat(null)->dataLabelFormatter);
        $this->assertNull($set->dataLabelFormat()->dataLabelFormatter);
    }

    public function testThemeAliasClearsKnobWithNull(): void
    {
        $themed = LineChart::new([0, 10], 5, 3)->theme(Theme::dark());
        $this->assertInstanceOf(Theme::class, $themed->theme);
        $this->assertStringContainsString("\x1b[38;", $themed->view(), 'sanity: themed arm must actually paint SGR');

        $cleared = $themed->theme(null);
        $this->assertNull($cleared->theme);
        $this->assertSame(LineChart::new([0, 10], 5, 3)->view(), $cleared->view(), 'null clear must return the unthemed bytes');
        $this->assertNull($themed->theme()->theme, 'bare theme() clears like canvas() does');
    }

    public function testNegativeAnimationDurationThrows(): void
    {
        // sugar-charts-057: last numeric door in the family now validates.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(Lang::t('chart.duration_nonneg'));
        LineChart::new()->withAnimationDuration(-1);
    }

    public function testZeroAndPositiveAnimationDurationAccepted(): void
    {
        $this->assertSame(0, LineChart::new()->withAnimationDuration(0)->getAnimationDuration());
        $this->assertSame(1200, LineChart::new()->withAnimationDuration(1200)->getAnimationDuration());
    }

    public function testStyleForColorCatchIsNarrowedNotThrowable(): void
    {
        // sugar-charts-058: \Error/\TypeError from a broken style stack must
        // surface as bugs, not collapse into unstyled points. No legitimate
        // input can raise those, so the pin is structural (source shape)
        // plus the behavioral edges the narrowed arms still swallow.
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/LineChart/LineChart.php');
        $start = strpos($src, 'private static function styleForColor');
        $this->assertNotFalse($start, 'styleForColor must exist to be pinned');
        $body = substr($src, (int) $start, 700);
        $this->assertStringNotContainsString('catch (\Throwable)', $body, 'outer catch must stay narrowed (058)');
        $this->assertStringContainsString('catch (\InvalidArgumentException | \UnexpectedValueException)', $body);

        $m = new \ReflectionMethod(LineChart::class, 'styleForColor');
        $red = $m->invoke(null, 'red');
        $this->assertInstanceOf(\SugarCraft\Style\Style::class, $red, 'valid color still styles');
        $this->assertNull($m->invoke(null, 'not-a-color-at-all'), 'junk input still degrades to unstyled');
    }

    public function testDurationLangKeyIsLiteralAtCallSiteAndResolves(): void
    {
        // Coverage-guard parity: the door spells the key literally and the
        // lookup resolves to the en wording (not the raw key).
        $msg = Lang::t('chart.duration_nonneg');
        $this->assertStringNotContainsString('chart.duration_nonneg', $msg);
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Chart/Chart.php');
        $this->assertStringContainsString("Lang::t('chart.duration_nonneg')", $src);
    }
}
