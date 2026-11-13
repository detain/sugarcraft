<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Aggregation\BucketByTime;
use SugarCraft\Charts\Aggregation\MovingAverage;
use SugarCraft\Charts\Aggregation\Resample;
use SugarCraft\Charts\Lang;
use SugarCraft\Charts\MarkLine;
use SugarCraft\Charts\OHLC\Candle;
use SugarCraft\Charts\Support\Range;
use SugarCraft\Core\I18n\T;

/**
 * sugar-charts-061: user-visible domain refusals must carry translated
 * strings (routed through Lang::t), while the wording stays stable under
 * the default 'en' locale so existing message pins keep holding.
 */
final class ThrowI18nTest extends TestCase
{
    /**
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function refusalProvider(): array
    {
        return [
            'range.min-exceeds-max' => [
                static fn () => Range::pin(10.0, 5.0, 'y'),
                'Invalid y range: min (10) must not exceed max (5)',
            ],
            'range.lone-pin-excludes-data' => [
                static fn () => Range::resolve(20.0, null, 0.0, 10.0, 'x'),
                'Invalid x range: min (20) must not exceed max (10) — a lone pinned end that excludes the whole data set leaves no drawable domain',
            ],
            'ohlc.high-below-low' => [
                static fn () => new Candle(1.0, 2.0, 5.0, 3.0),
                'Invalid OHLC bar: high (2) must not be below low (5)',
            ],
            'markline.empty-dataset' => [
                static fn () => MarkLine::fromDataset([], MarkLine::MIN),
                'Cannot derive MarkLine from empty dataset',
            ],
            'markline.invalid-type' => [
                static fn () => MarkLine::fromDataset([1.0, 2.0], 'median'),
                'Invalid MarkLine type: median (expected one of: min, max, average)',
            ],
            'markline.invalid-style' => [
                static fn () => MarkLine::at(1.0, '', 'zigzag'),
                'Invalid MarkLine style: zigzag (expected one of: solid, dashed, dotted)',
            ],
            'movingaverage.window-positive' => [
                static fn () => MovingAverage::simple(0),
                'Window size must be > 0, got 0',
            ],
            'movingaverage.alpha-range' => [
                static fn () => MovingAverage::ema(3, [1.0, 2.0], 5.0),
                'EMA alpha must be in (0, 1], got 5',
            ],
            'bucketbytime.interval-positive' => [
                static fn () => BucketByTime::sum(0),
                'Bucket interval must be > 0, got 0',
            ],
            'resample.interval-positive' => [
                static fn () => Resample::new(0),
                'Target interval must be > 0, got 0',
            ],
        ];
    }

    /**
     * @dataProvider refusalProvider
     */
    public function testRefusalCarriesTheRoutedEnglishWording(callable $fire, string $exact): void
    {
        try {
            $fire();
            self::fail('expected refusal: ' . $exact);
        } catch (InvalidArgumentException $e) {
            self::assertSame($exact, $e->getMessage());
        }
    }

    /**
     * @dataProvider refusalProvider
     */
    public function testRefusalNeverLeaksTheTranslationKey(callable $fire, string $exact): void
    {
        try {
            $fire();
            self::fail('expected refusal');
        } catch (InvalidArgumentException $e) {
            self::assertStringNotContainsString('charts.', $e->getMessage());
            // An unregistered namespace renders the raw dotted key — the
            // phrasing above proves the catalog answered instead.
            self::assertNotSame('', $e->getMessage());
        }
    }

    public function testGermanLocaleTranslatesTheRoutedRefusals(): void
    {
        T::setLocale('de');
        try {
            try {
                new Candle(1.0, 2.0, 5.0, 3.0);
                self::fail('expected refusal');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('darf nicht unter', $e->getMessage());
            }
            try {
                MovingAverage::simple(0);
                self::fail('expected refusal');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Fenstergröße muss > 0 sein, erhalten 0', $e->getMessage());
            }
            try {
                MarkLine::fromDataset([], MarkLine::MIN);
                self::fail('expected refusal');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('leeren Datensatz', $e->getMessage());
            }
        } finally {
            T::setLocale('en');
        }
    }

    public function testAllSixteenLocalesCarryTheNewKeys(): void
    {
        $keys = [
            'range.min_exceeds_max',
            'range.pinned_min_exceeds_max',
            'ohlc.high_below_low',
            'markline.empty_dataset',
            'markline.invalid_type',
            'markline.invalid_style',
            'movingaverage.window_positive',
            'movingaverage.alpha_range',
            'bucketbytime.interval_positive',
            'resample.interval_positive',
        ];
        $files = glob(__DIR__ . '/../lang/*.php');
        self::assertCount(16, $files, 'sixteen locale files expected');
        foreach ($files as $f) {
            /** @var array<string, string> $catalog */
            $catalog = require $f;
            foreach ($keys as $k) {
                self::assertArrayHasKey($k, $catalog, basename($f) . ' missing ' . $k);
            }
        }
    }

    public function testRoutedFilesCarryNoRawEnglishDomainThrows(): void
    {
        // Convention pin: an InvalidArgumentException/RuntimeException whose
        // argument starts with a string literal in one of these files means
        // a refusal bypassed the catalog (CH-46) — it will never translate.
        $files = [
            'Support/Range.php',
            'OHLC/Candle.php',
            'MarkLine.php',
            'Aggregation/MovingAverage.php',
            'Aggregation/BucketByTime.php',
            'Aggregation/Resample.php',
        ];
        foreach ($files as $rel) {
            $body = (string) file_get_contents(__DIR__ . '/../src/' . $rel);
            self::assertSame(
                0,
                preg_match_all('/throw new \\\(?:InvalidArgumentException|RuntimeException)\(\s*(?:sprintf\(\s*)?[\'"]/', $body),
                $rel . ' throws a raw string literal'
            );
        }
    }

    public function testPlaceholderTokensSurviveEveryLocale(): void
    {
        // i18n law: {tokens} are catalog structure, not prose — a locale
        // that dropped one would silently print un-substituted braces.
        $expect = [
            'range.min_exceeds_max' => ['{axis}', '{min}', '{max}'],
            'range.pinned_min_exceeds_max' => ['{axis}', '{min}', '{max}'],
            'ohlc.high_below_low' => ['{high}', '{low}'],
            'markline.invalid_type' => ['{type}', '{allowed}'],
            'markline.invalid_style' => ['{style}', '{allowed}'],
            'movingaverage.window_positive' => ['{given}'],
            'movingaverage.alpha_range' => ['{given}'],
            'bucketbytime.interval_positive' => ['{given}'],
            'resample.interval_positive' => ['{given}'],
        ];
        foreach (glob(__DIR__ . '/../lang/*.php') as $f) {
            /** @var array<string, string> $catalog */
            $catalog = require $f;
            foreach ($expect as $key => $tokens) {
                foreach ($tokens as $token) {
                    self::assertStringContainsString(
                        $token,
                        $catalog[$key],
                        basename($f) . ': ' . $key . ' lost ' . $token
                    );
                }
            }
        }
        self::assertTrue(Lang::t('markline.empty_dataset') !== '', 'catalog answers');
    }
}
