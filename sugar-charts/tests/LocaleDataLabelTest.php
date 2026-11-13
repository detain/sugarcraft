<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Scatter\Scatter;

/**
 * CH-12 (sugar-charts-045, -063): data labels must survive a comma
 * decimal locale, and non-numeric ingestion must throw instead of
 * plotting a silent 0.0.
 */
final class LocaleDataLabelTest extends TestCase
{
    private static function plain(string $out): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $out) ?? '';
    }

    public function testCommaLocaleStillEmitsDotDecimal(): void
    {
        $prev = setlocale(LC_NUMERIC, '0');
        if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'German') === false) {
            self::markTestSkipped('de_DE locale not installed on this host');
        }
        try {
            // Prove the locale actually poisoned the C library, else the
            // pin below would be vacuous (a needle that cannot discriminate).
            if (localeconv()['decimal_point'] !== ',') {
                self::markTestSkipped('setlocale accepted but decimal_point unchanged');
            }
            self::assertStringContainsString(',', sprintf('%.2f', 3.14159), 'poison assertion itself');
            $out = self::plain(BarChart::new(['a' => 3.14159], 12, 4)->withDataLabels()->view());
            self::assertStringContainsString('3.14', $out, 'comma locale leaked into the data label');
            self::assertStringNotContainsString('3,14', $out);
        } finally {
            setlocale(LC_NUMERIC, (string) $prev);
        }
    }

    public function testLabelFormattingIsLocaleIndependentAndTrimmed(): void
    {
        // Deterministic legs — no host locale required. number_format's
        // half-away-from-zero is the in-repo sound precedent (Heatmap),
        // a hair from %f's half-even on exact binary halves.
        $render = static fn (float $v): string => self::plain(
            BarChart::new(['a' => $v], 14, 4)->withDataLabels()->view()
        );
        self::assertStringContainsString('3.14', $render(3.14159));
        self::assertStringContainsString('2.5', $render(2.50));
        self::assertStringNotContainsString('2.50', $render(2.50), 'trailing zero strip regressed');
        self::assertStringContainsString('12', $render(12.0));
        self::assertStringNotContainsString('12.00', $render(12.0));
        self::assertStringContainsString('0.13', $render(0.125));
        self::assertStringContainsString('-3.14', $render(-3.14));
    }

    public function testNonNumericBarValueThrowsInsteadOfPlottingZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BarChart::new([['a', 'abc']]);
    }

    public function testNonNumericBarValueThrowsViaEverySeam(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BarChart::new(['a' => 1.0])->withBars(['b' => 'nope']);
    }

    public function testPushAndPushAllShareTheNumericDoor(): void
    {
        $chart = BarChart::new(['a' => 1.0]);
        try {
            $chart->push(['b', true]);
            self::fail('boolean value sailed through the door');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('bool', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $chart->pushAll([['c', null]]);
    }

    public function testNumericStringIsAccepted(): void
    {
        $chart = BarChart::new([['a', '3.5']]);
        self::assertStringContainsString('3.5', self::plain($chart->withDataLabels()->view()));
    }

    public function testScatterNonNumericPointThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scatter::new([['abc', 1.0]]);
    }

    public function testScatterNumericStringsStillAccepted(): void
    {
        $chart = Scatter::new([['1', '2'], [3.5, 4]]);
        self::assertNotSame('', $chart->view());
    }

    public function testScatterWrongShapeThrowsNotWarns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scatter::new([['x']]);
    }
}
