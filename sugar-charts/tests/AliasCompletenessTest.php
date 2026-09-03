<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Chart\Chart;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\Streamline;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\LineChart\Waveline;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Dash\Plot\Braille\BrailleCanvas;

/**
 * Alias-completeness registry (BL-4).
 *
 * Every public instance `with*()` fluent setter declared on a chart class must
 * have a short-form alias reachable on that same class. The naming convention
 * is `withFoo() → foo()` (drop `with`, lcfirst); where the codebase deviates the
 * deviation is recorded in {@see ALIAS_EXCEPTIONS}.
 *
 * Inheritance semantics: LineChart overrides several Chart-base withers
 * (`withTitle`, `withCanvas`, `withTheme`, `withAnimationProgress`,
 * `withAnimationDuration`) so their clones route through `lineChartCopy()` and
 * keep LineChart identity. Their short-form aliases are declared ONCE on the
 * Chart base and are inherited by LineChart. Because each base alias delegates
 * via `$this->with…()` (PHP late binding), a call such as `$lineChart->title('x')`
 * resolves to the inherited `Chart::title()`, whose internal `$this->withTitle()`
 * then dispatches to `LineChart::withTitle()`. The census therefore counts an
 * inherited alias as coverage: it asserts `method_exists($class, $alias)`, which
 * is true whether the alias is declared locally or inherited. No duplicate alias
 * methods are needed on LineChart — and none are permitted.
 */
final class AliasCompletenessTest extends TestCase
{
    /**
     * withers whose short form does NOT follow the plain drop-`with` lcfirst rule.
     *
     * @var array<string,string> long method name => expected short alias
     */
    private const ALIAS_EXCEPTIONS = [
        'withLegendPosition'     => 'legendPos',       // Position → Pos
        'withTitlePosition'      => 'titlePos',        // Position → Pos (mirrors legendPos)
        'withDataLabelFormatter' => 'dataLabelFormat', // …Formatter → …Format
        'withFractionalHeights'  => 'fractional',      // …Heights dropped
        'withXYRange'            => 'xyRange',         // acronym: XY → xy, not xY
    ];

    /**
     * The Chart base plus every concrete chart / legend / picture component.
     *
     * @var list<class-string>
     */
    private const CLASSES = [
        Chart::class,
        Sparkline::class,
        BarChart::class,
        LineChart::class,
        Streamline::class,
        Waveline::class,
        TimeSeries::class,
        Heatmap::class,
        OHLCChart::class,
        Scatter::class,
        Picture::class,
        Legend::class,
    ];

    /**
     * Reflect over every class; for each public instance wither DECLARED on it,
     * assert its expected short alias is reachable (locally or inherited). Any
     * gap is collected then asserted empty so the message names every offender.
     */
    public function testEveryDeclaredWitherHasAShortAlias(): void
    {
        $missing   = [];
        $checked   = 0;
        $exception = 0;

        foreach (self::CLASSES as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || $method->isAbstract()) {
                    continue;
                }
                // Only withers DECLARED on this class — inherited ones are checked
                // at their own declaring class, avoiding double-counting.
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                if (preg_match('/^with[A-Z]/', $method->getName()) !== 1) {
                    continue;
                }

                $checked++;
                $alias = self::expectedAlias($method->getName());
                if (isset(self::ALIAS_EXCEPTIONS[$method->getName()])) {
                    $exception++;
                }
                // method_exists counts inherited aliases → coverage via late binding.
                if (!method_exists($class, $alias)) {
                    $missing[] = "{$class}::{$method->getName()}() → missing alias {$alias}()";
                }
            }
        }

        $this->assertSame([], $missing, "Alias gaps detected:\n" . implode("\n", $missing));
        // The registry is meaningful: it must cover the full 125-wither surface
        // (123 from BL-4 + withMarkLines added by B2 + withUnicodeConnectors
        // added by B3), including every documented
        // deviation. A count collapse here would mean the reflection silently
        // stopped seeing withers.
        $this->assertSame(125, $checked, 'wither census count drifted from the documented 125');
        $this->assertGreaterThanOrEqual(5, $exception, 'documented alias deviations must all be present');
    }

    public function testAliasConventionMatchesDocumentedDeviations(): void
    {
        // Deviations.
        $this->assertSame('legendPos', self::expectedAlias('withLegendPosition'));
        $this->assertSame('titlePos', self::expectedAlias('withTitlePosition'));
        $this->assertSame('dataLabelFormat', self::expectedAlias('withDataLabelFormatter'));
        $this->assertSame('fractional', self::expectedAlias('withFractionalHeights'));
        $this->assertSame('xyRange', self::expectedAlias('withXYRange'));
        // Plain drop-with lcfirst for the rest.
        $this->assertSame('title', self::expectedAlias('withTitle'));
        $this->assertSame('canvas', self::expectedAlias('withCanvas'));
        $this->assertSame('noAutoBarWidth', self::expectedAlias('withNoAutoBarWidth'));
        $this->assertSame('noAutoMaxValue', self::expectedAlias('withNoAutoMaxValue'));
        $this->assertSame('datasetPoint', self::expectedAlias('withDatasetPoint'));
        $this->assertSame('xLabelFormatter', self::expectedAlias('withXLabelFormatter'));
    }

    /**
     * Sampled value-parity: for a representative spread of NEW and existing
     * aliases, the long-form and short-form chains must render byte-identical.
     * Covers inherited (Chart→LineChart) dispatch and per-class local aliases.
     */
    public function testSampledAliasValueParity(): void
    {
        // BarChart local aliases: noAutoBarWidth + titlePos.
        $this->assertSame(
            BarChart::new([['a', 1.0], ['b', 2.0]])->size(20, 5)->withNoAutoBarWidth()->view(),
            BarChart::new([['a', 1.0], ['b', 2.0]])->size(20, 5)->noAutoBarWidth()->view(),
            'BarChart::noAutoBarWidth() must match withNoAutoBarWidth()',
        );
        $this->assertSame(
            BarChart::new([['a', 1.0]])->size(16, 4)->title('T', Position::Bottom)->withTitlePosition(Position::Bottom)->view(),
            BarChart::new([['a', 1.0]])->size(16, 4)->title('T')->titlePos(Position::Bottom)->view(),
            'BarChart::titlePos() must match withTitlePosition()',
        );

        // Waveline aliases: size / xyRange / point.
        $this->assertSame(
            Waveline::new()->withSize(20, 4)->withXYRange(0.0, 10.0, 0.0, 5.0)->withPoint('*')->view(),
            Waveline::new()->size(20, 4)->xyRange(0.0, 10.0, 0.0, 5.0)->point('*')->view(),
            'Waveline short forms must match their with* long forms',
        );

        // TimeSeries aliases: timeFormat / xLabelCount (fixed timestamps → deterministic).
        $p0 = new DateTimeImmutable('2026-01-01 10:00:00');
        $p1 = new DateTimeImmutable('2026-01-01 10:05:00');
        $this->assertSame(
            TimeSeries::new([[$p0, 1.0], [$p1, 2.0]])->withTimeFormat('G:i')->withXLabelCount(3)->view(),
            TimeSeries::new([[$p0, 1.0], [$p1, 2.0]])->timeFormat('G:i')->xLabelCount(3)->view(),
            'TimeSeries::timeFormat()/xLabelCount() must match their with* long forms',
        );

        // Streamline aliases: size / min / max / yRange / point.
        $this->assertSame(
            Streamline::new()->withSize(20, 4)->withMin(0.0)->withMax(9.0)->withYRange(1.0, 8.0)->withPoint('#')->view(),
            Streamline::new()->size(20, 4)->min(0.0)->max(9.0)->yRange(1.0, 8.0)->point('#')->view(),
            'Streamline short forms must match their with* long forms',
        );

        // Heatmap aliases: colorProfile / autoValueRange.
        $this->assertSame(
            Heatmap::new()->size(4, 3)->withAutoValueRange(false)->view(),
            Heatmap::new()->size(4, 3)->autoValueRange(false)->view(),
            'Heatmap::autoValueRange() must match withAutoValueRange()',
        );

        // Picture alias: paletteSize.
        $this->assertSame(
            Picture::fromPng('')->withPaletteSize(8)->view(),
            Picture::fromPng('')->paletteSize(8)->view(),
            'Picture::paletteSize() must match withPaletteSize()',
        );

        // Legend aliases: items / position / showBorder / indicatorChar.
        $items = [['label' => 'a', 'color' => '#f00']];
        $this->assertSame(
            Legend::new($items)->withPosition(Position::Bottom)->withShowBorder(false)->indicatorChar('#')->view(),
            Legend::new($items)->position(Position::Bottom)->showBorder(false)->indicatorChar('#')->view(),
            'Legend short forms must match their with* long forms',
        );

        // LineChart: inherited-from-Chart aliases (title / animationProgress) plus
        // LineChart-local aliases (dataset / datasetPoint) must all reach the
        // lineChartCopy-backed overrides via late binding → identical bytes.
        $this->assertSame(
            LineChart::new([1.0, 2.0, 3.0])
                ->withSize(20, 5)
                ->withTitle('Series', Position::Top)
                ->withDataset('a', [1.0, 2.0, 3.0])
                ->withDatasetPoint('a', 'o')
                ->withAnimationProgress(1.0)
                ->view(),
            LineChart::new([1.0, 2.0, 3.0])
                ->size(20, 5)
                ->title('Series', Position::Top)
                ->dataset('a', [1.0, 2.0, 3.0])
                ->datasetPoint('a', 'o')
                ->animationProgress(1.0)
                ->view(),
            'LineChart must preserve subclass identity through inherited + local aliases',
        );

        // Chart::canvas() alias delegates to withCanvas() and keeps subclass identity.
        $canvas = new BrailleCanvas(20, 8);
        $chart  = LineChart::new([1.0, 2.0])->size(20, 8);
        $this->assertInstanceOf(
            LineChart::class,
            $chart->canvas($canvas),
            'LineChart::canvas() (inherited) must return a LineChart via lineChartCopy late binding',
        );
        $this->assertSame(
            $chart->withCanvas($canvas)->view(),
            $chart->canvas($canvas)->view(),
            'Chart::canvas() must match withCanvas()',
        );
    }

    private static function expectedAlias(string $method): string
    {
        return self::ALIAS_EXCEPTIONS[$method] ?? lcfirst(substr($method, 4));
    }
}
