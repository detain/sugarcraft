<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Chart\Chart;
use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\LineStyle;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\Streamline;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\LineChart\Waveline;
use SugarCraft\Charts\OHLC\Bar as OhlcBar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;
use SugarCraft\Style\Theme;

/**
 * Full-roster alias PARITY: every declared `with*()` setter must render
 * byte-identical output to its short alias when fed the same arguments
 * (sugar-charts-070). AliasCompletenessTest proves each alias EXISTS and is
 * NAMED by convention; the 9 samples there proved value-parity only for a
 * hand-picked slice — an alias that exists but diverges from its long form
 * slipped through. The roster here is reflection-derived (same scan), so a
 * new wither is parity-checked automatically the moment it lands.
 *
 * Arguments are synthesized per parameter type, with per-method overrides
 * where the signature demands a concrete shape, and a render-or-refusal
 * capture so a pair whose synthetic args legitimately refuse (documented
 * LineChart data-label ban) still compares — vacuity is guarded: both
 * spellings must reach a render unless the pair is allow-listed.
 */
final class AliasParityTest extends TestCase
{
    /** @var list<class-string> same roster as AliasCompletenessTest */
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
     * withers whose short form breaks the plain drop-`with` lcfirst rule —
     * mirrored from AliasCompletenessTest on purpose: drift between the two
     * maps shows up here as a missing-alias failure, never silently.
     *
     * @var array<string,string>
     */
    private const ALIAS_EXCEPTIONS = [
        'withLegendPosition'     => 'legendPos',
        'withTitlePosition'      => 'titlePos',
        'withDataLabelFormatter' => 'dataLabelFormat',
        'withFractionalHeights'  => 'fractional',
        'withXYRange'            => 'xyRange',
    ];

    /**
     * Pairs allowed to compare as IDENTICAL refusals instead of renders:
     * LineChart refuses data labels by documented contract (the message
     * names BarChart as the home of that feature), so the Chart-base and
     * LineChart pairs for these two can never reach a render on any
     * fixture that also carries the rest of the Chart surface.
     *
     * @var list<string> "Short::method"
     */
    private const REFUSALS = [
        'Chart::withDataLabels',
        'Chart::withDataLabelFormatter',
        'LineChart::withDataLabels',
        'LineChart::withDataLabelFormatter',
    ];

    /** @return array<string,array{0:class-string,1:string,2:string}> */
    public static function rosterProvider(): array
    {
        $cases = [];
        foreach (self::CLASSES as $class) {
            $reflection = new ReflectionClass($class);
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || $method->isAbstract()) {
                    continue;
                }
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                if (preg_match('/^with[A-Z]/', $method->getName()) !== 1) {
                    continue;
                }
                $alias = self::ALIAS_EXCEPTIONS[$method->getName()]
                    ?? lcfirst(substr($method->getName(), 4));
                $short = $reflection->getShortName();
                $cases["{$short}::{$method->getName()}"] = [$class, $method->getName(), $alias];
            }
        }
        return $cases;
    }

    /** @dataProvider rosterProvider */
    public function testAliasRendersByteIdenticalToWither(string $class, string $wither, string $alias): void
    {
        $this->assertTrue(method_exists($class, $alias), "{$class}::{$alias}() vanished from the alias surface");

        $method    = new ReflectionMethod($class, $wither);
        $args      = $this->argumentsFor($class, $wither, $method);
        $base      = $this->fixture($class);
        $short     = substr($class, (int) strrpos($class, '\\') + 1);
        $viaWither = $this->capture($base, $wither, $args);
        $viaAlias  = $this->capture($base, $alias, $args);

        $this->assertSame($viaWither, $viaAlias, "alias {$class}::{$alias}() diverges from {$wither}()");

        $rendered = str_starts_with($viaWither, 'render:');
        $refused  = in_array("{$short}::{$wither}", self::REFUSALS, true) && str_starts_with($viaWither, 'throw:');
        $this->assertTrue(
            $rendered || $refused,
            "synthetic args never reached a render for {$class}::{$wither}: {$viaWither}"
        );
    }

    public function testRosterMatchesCompletenessCensus(): void
    {
        $this->assertSame(
            152,
            count(self::rosterProvider()),
            'parity roster no longer covers the 152-wither alias surface'
        );
    }

    /**
     * @param class-string $class
     * @return list<mixed>
     */
    private function argumentsFor(string $class, string $wither, ReflectionMethod $method): array
    {
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        foreach ($this->overrides() as $key => $args) {
            if ($key === "{$short}::{$wither}" || $key === "*::{$wither}") {
                return $args;
            }
        }
        $args = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if ($type === null) {
                $args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : 1;
                continue;
            }
            if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
                $args[] = 1;
                continue;
            }
            $args[] = $this->synth($param->getName(), $type->getName());
        }
        return $args;
    }

    /** @return array<string,list<mixed>> */
    private function overrides(): array
    {
        $items = [['label' => 'q1', 'color' => 'red'], ['label' => 'q2', 'color' => 'blue']];
        return [
            '*::withLegendItems'        => [$items],
            '*::withItems'              => [$items],
            'BarChart::withBars'        => [[['a', 3], ['b', 7]]],
            'OHLCChart::withBars'       => [[new OhlcBar(1.0, 4.0, 0.5, 2.0), new OhlcBar(2.0, 5.0, 1.5, 3.0)]],
            'Scatter::withPoints'       => [[[1, 2], [3, 5], [7, 4]]],
            'Waveline::withPoints'      => [[[1, 2], [3, 5], [7, 4]]],
            'TimeSeries::withPoints'    => [[[new \DateTimeImmutable('@0'), 1.0], [new \DateTimeImmutable('@600'), 4.0]]],
            'Heatmap::withGrid'         => [[[1, 2, 3], [4, 5, 6]]],
            'LineChart::withMarkLines'  => [[]],
            'TimeSeries::withTimeRange' => [new \DateTimeImmutable('@0'), new \DateTimeImmutable('@600')],
            '*::withTheme'              => [Theme::dark()],
            '*::withCanvas'             => [new Canvas(24, 12)],
            '*::withCellStyle'          => [Style::new()->fg(Color::ansi(4))],
            '*::withSeriesColorFn'      => [static fn(mixed ...$a): ?Color => Color::ansi(2)],
            '*::withBarColor'           => [static fn(mixed ...$a): ?Color => Color::ansi(2)],
            '*::withXLabelFormatter'    => [static fn(mixed ...$a): string => 'x' . $a[0]],
            '*::withYLabelFormatter'    => [static fn(mixed ...$a): string => 'y' . $a[0]],
            '*::withDataLabelFormatter' => [static fn(mixed ...$a): string => 'v' . $a[0]],
            'LineChart::withLineStyle'  => [null],
            'TimeSeries::withLineStyle' => [null],
            'TimeSeries::withTimeFormat' => ['H:i'],
            'Heatmap::withPalette'       => [[Color::ansi(2), Color::ansi(6), Color::ansi(4)]],
            '*::withTitle'               => ['parity', Position::Bottom],
            '*::withTitlePosition'       => [Position::Bottom],
            'OHLCChart::withBodyRunes'   => ['▲', '▼', '─'],
        ];
    }

    private function synth(string $paramName, string $typeName): mixed
    {
        if (enum_exists($typeName)) {
            return match ($typeName) {
                Position::class => Position::Right,
                LineStyle::class => LineStyle::Dotted,
                default         => $typeName::cases()[0],
            };
        }
        return match ($typeName) {
            'int'      => 7,
            'float'    => 2.0,
            'bool'     => true,
            'string'   => $this->stringFor($paramName),
            'array'    => $this->arrayFor($paramName),
            'callable', \Closure::class => static fn(mixed ...$a): string => 'v',
            Color::class  => Color::ansi(4),
            Style::class  => Style::new()->fg(Color::ansi(4)),
            Canvas::class => new Canvas(24, 12),
            default   => 1,
        };
    }

    private function stringFor(string $paramName): string
    {
        if (preg_match('/rune|char|point|marker|^r$/i', $paramName) === 1) {
            return '*';
        }
        if (preg_match('/format/i', $paramName) === 1) {
            return '%.1f';
        }
        if (preg_match('/name|label|title/i', $paramName) === 1) {
            return 'parity';
        }
        return 'pa';
    }

    /** @return list<mixed> */
    private function arrayFor(string $paramName): array
    {
        if (preg_match('/items/i', $paramName) === 1) {
            return [['label' => 'q1', 'color' => 'red']];
        }
        if (preg_match('/bars/i', $paramName) === 1) {
            return [['label' => 'a', 'value' => 3]];
        }
        if (preg_match('/points/i', $paramName) === 1) {
            return [[1, 2]];
        }
        if (preg_match('/labels/i', $paramName) === 1) {
            return ['alpha', 'beta'];
        }
        if (preg_match('/marks/i', $paramName) === 1) {
            return [];
        }
        if (preg_match('/grid/i', $paramName) === 1) {
            return [[1, 2]];
        }
        return [1.0, 2.0, 3.0];
    }

    /** @param class-string $class */
    private function fixture(string $class): object
    {
        return match ($class) {
            // Chart is abstract; LineChart is its only subclass, so every
            // Chart-declared wither is exercised through that late binding.
            Chart::class => LineChart::new([1.0, 5.0, 3.0, 7.0, 2.0], 24, 12)->withAxes(true),
            LineChart::class => LineChart::new([1.0, 5.0, 3.0, 7.0, 2.0], 24, 12)->withAxes(true),
            Streamline::class => Streamline::new(24, 12),
            Waveline::class => Waveline::new([[1, 2], [3, 5]], 24, 12),
            TimeSeries::class => TimeSeries::new([[new \DateTimeImmutable('@0'), 1.0], [new \DateTimeImmutable('@600'), 4.0]], 24, 12),
            Sparkline::class => Sparkline::new([1, 2, 3, 4, 5, 6], 20),
            BarChart::class => BarChart::new([['a', 3], ['b', 7]], 20, 8),
            Scatter::class => Scatter::new([[1, 2], [3, 5], [7, 4]], 20, 8),
            OHLCChart::class => OHLCChart::new([new OhlcBar(1.0, 4.0, 0.5, 2.0), new OhlcBar(2.0, 5.0, 1.5, 3.0)], 20, 8),
            Heatmap::class => Heatmap::new([[1, 2, 3], [4, 5, 6]]),
            Picture::class => Picture::fromGrid([[Color::ansi(1), Color::ansi(2)]]),
            Legend::class => Legend::new([['label' => 'q1', 'color' => 'red'], ['label' => 'q2', 'color' => 'blue']]),
            default => throw new \LogicException("no fixture for {$class}"),
        };
    }

    /**
     * Apply a mutation and reduce the outcome to a comparable string:
     * rendered bytes, or the domain refusal (class + message). A refusal
     * raised by only one spelling is still a divergence — equality of the
     * capture catches it.
     *
     * @param list<mixed> $args
     */
    private function capture(object $base, string $method, array $args): string
    {
        try {
            return 'render:' . (string) $base->{$method}(...$args)->view();
        } catch (\InvalidArgumentException | \TypeError | \UnexpectedValueException $e) {
            return 'throw:' . $e::class . '|' . $e->getMessage();
        }
    }
}
