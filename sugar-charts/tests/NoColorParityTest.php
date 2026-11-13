<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\BarChart\BarChart;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\LineChart\TimeSeries;
use SugarCraft\Charts\OHLC\Bar;
use SugarCraft\Charts\OHLC\OHLCChart;
use SugarCraft\Charts\Picture\Picture;
use SugarCraft\Charts\Picture\Protocol;
use SugarCraft\Charts\Scatter\Scatter;
use SugarCraft\Charts\Sparkline\Sparkline;
use SugarCraft\Charts\Support\ColorLadder;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;

/**
 * CH-36 (sugar-charts-031): the NO_COLOR / CLICOLOR ladder must govern
 * every emission site, not just the legend indicator.
 *
 * Env fixtures flip putenv around each call (restored in finally — the
 * CH-23 pattern). Assertions are byte-level: a suppressed render carries
 * zero SGR and keeps every glyph, and each surface's un-suppressed render
 * is provably color-carrying, so the zero-SGR arms can never be vacuous.
 */
final class NoColorParityTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $envSave = [];

    /** @return array<string, array{0: callable(): string, 1: bool}> render + emits-color-by-default */
    public static function surfaceProvider(): array
    {
        $items = [['label' => 'A', 'color' => 'red'], ['label' => 'B', 'color' => 'green']];

        return [
            'LineChart (legend strokes)' => [
                static fn(): string => LineChart::new([1.0, 5.0, 3.0], 20, 6)
                    ->withLegend(true)->withLegendItems($items)->view(),
                true,
            ],
            'BarChart (bordered legend)' => [
                static fn(): string => BarChart::new([['a', 1.0], ['b', 2.0]], 20, 6)
                    ->withLegend(true)->withLegendItems($items)->view(),
                true,
            ],
            'Heatmap (value ramp)' => [
                static fn(): string => Heatmap::new([[1.0, 2.0], [3.0, 4.0]], 6, 2)->view(),
                true,
            ],
            'Scatter (points)' => [
                static fn(): string => Scatter::new([[1, 2], [3, 4]], 20, 6)->view(),
                false,
            ],
            'OHLCChart (bodies)' => [
                static fn(): string => OHLCChart::new(
                    [new Bar(open: 100.0, high: 110.0, low: 95.0, close: 108.0)], 10, 10,
                )->view(),
                true,
            ],
            'Sparkline (styled)' => [
                static fn(): string => Sparkline::new([1, 5, 3], 8)
                    ->withStyle(Style::new()->fg(Color::ansi(2)))->view(),
                true,
            ],
            'TimeSeries (legend strokes)' => [
                static fn(): string => TimeSeries::new(
                    [[new \DateTimeImmutable('2026-01-01'), 1.0], [new \DateTimeImmutable('2026-01-02'), 5.0]],
                    20, 6,
                )->withLegendItems($items)->withLegend(true)->view(),
                true,
            ],
            'Legend (direct)' => [
                static fn(): string => Legend::new($items)->withShowBorder(true)->view(),
                true,
            ],
        ];
    }

    /**
     * @param callable(): string $render
     */
    /** @dataProvider surfaceProvider */
    public function testNoColorEmptiesEveryColorSurface(callable $render, bool $emitsByDefault): void
    {
        $this->withEnv(['NO_COLOR' => '1'], function () use ($render): void {
            $out = $render();
            $this->assertFalse(
                ColorLadder::carriesSgr($out),
                'NO_COLOR=1 must leave zero SGR bytes in this render',
            );
        });
        if (!$emitsByDefault) {
            return;
        }
        $this->withEnv([], function () use ($render): void {
            $this->assertTrue(
                ColorLadder::carriesSgr($render()),
                'the same fixture must carry SGR when nothing suppresses it (non-vacuity guard)',
            );
        });
    }

    /**
     * @param callable(): string $render
     */
    /** @dataProvider surfaceProvider */
    public function testSuppressedRenderKeepsEveryGlyph(callable $render, bool $emitsByDefault): void
    {
        $plain = $this->withEnv([], $render);
        $off = $this->withEnv(['NO_COLOR' => '1'], $render);
        $this->assertSame(
            ColorLadder::stripSgr($plain),
            $off,
            'suppression must remove exactly the SGR sequences and nothing else',
        );
    }

    /**
     * @param callable(): string $render
     */
    /** @dataProvider surfaceProvider */
    public function testNoColorZeroIsStillSuppression(callable $render, bool $emitsByDefault): void
    {
        // no-color.org: presence is the signal, not the value.
        $this->withEnv(['NO_COLOR' => '0'], function () use ($render): void {
            $this->assertFalse(ColorLadder::carriesSgr($render()));
        });
    }

    /**
     * @param callable(): string $render
     */
    /** @dataProvider surfaceProvider */
    public function testClicolorZeroDisablesAndForceReEnables(callable $render, bool $emitsByDefault): void
    {
        $this->withEnv(['CLICOLOR' => '0'], function () use ($render): void {
            $this->assertFalse(ColorLadder::carriesSgr($render()), 'CLICOLOR=0 disables by BSD convention');
        });
        if (!$emitsByDefault) {
            return;
        }
        $this->withEnv(['CLICOLOR' => '0', 'NO_COLOR' => '1', 'CLICOLOR_FORCE' => '1'], function () use ($render): void {
            $this->assertTrue(
                ColorLadder::carriesSgr($render()),
                'CLICOLOR_FORCE wins the ladder outright',
            );
        });
    }

    /**
     * @param callable(): string $render
     */
    /** @dataProvider surfaceProvider */
    public function testClicolorForceReigns(callable $render, bool $emitsByDefault): void
    {
        if (!$emitsByDefault) {
            $this->markTestSkipped('colourless surface cannot demonstrate the force arm');
        }
        $this->withEnv(['FORCE_COLOR' => '1', 'NO_COLOR' => '1'], function () use ($render): void {
            $this->assertTrue(ColorLadder::carriesSgr($render()), 'FORCE_COLOR outranks NO_COLOR');
        });
        $this->withEnv(['FORCE_COLOR' => '', 'NO_COLOR' => '1'], function () use ($render): void {
            $this->assertFalse(ColorLadder::carriesSgr($render()), 'an EMPTY FORCE_COLOR is not a force');
        });
        $this->withEnv(['FORCE_COLOR' => '0', 'NO_COLOR' => '1'], function () use ($render): void {
            $this->assertFalse(ColorLadder::carriesSgr($render()), 'FORCE_COLOR=0 is not a force either');
        });
    }

    public function testPicturePayloadSurvivesSuppression(): void
    {
        $grid = array_map(
            static fn(int $r): array => array_map(
                static fn(int $c): Color => Color::rgb($r * 8 % 256, 64, $c * 8 % 256),
                range(0, 15),
            ),
            range(0, 15),
        );
        $pic = Picture::fromGrid($grid)->withProtocol(Protocol::Sixel);
        $lit = $this->withEnv([], static fn(): string => $pic->view());
        $off = $this->withEnv(['NO_COLOR' => '1'], static fn(): string => $pic->view());
        $this->assertStringContainsString("\x1bP", $off, 'the DCS/Sixel payload is image data, not styling');
        $this->assertSame($lit, $off, 'Sixel payload must pass the ladder untouched');
        $this->assertFalse(ColorLadder::carriesSgr($off));
    }

    public function testPlaceholderTextHonoursTheLadder(): void
    {
        // Empty grid renders the fallback placeholder. It is plain text
        // today, so the honest claim is: the placeholder is SGR-free in
        // both ladder states (nothing smuggles styling into the
        // suppressed arm), and the literal is the documented default.
        $pic = Picture::fromGrid([]);
        $lit = $this->withEnv([], static fn(): string => $pic->view());
        $off = $this->withEnv(['NO_COLOR' => '1'], static fn(): string => $pic->view());
        $this->assertSame($lit, $off);
        $this->assertFalse(ColorLadder::carriesSgr($lit), 'placeholder carries no styling even unsuppressed');
        $this->assertStringContainsString('image', $off);
    }

    public function testLadderIsSharedNotDuplicated(): void
    {
        foreach (['Legend/Legend', 'Chart/Chart', 'BarChart/BarChart', 'Heatmap/Heatmap', 'Scatter/Scatter',
            'OHLC/OHLCChart', 'Sparkline/Sparkline', 'Picture/Picture', 'LineChart/TimeSeries'] as $rel) {
            $src = (string) file_get_contents(\dirname(__DIR__) . '/src/' . $rel . '.php');
            $this->assertStringContainsString('ColorLadder::', $src, "{$rel} must consult the shared ladder");
        }
        $legend = (string) file_get_contents(\dirname(__DIR__) . '/src/Legend/Legend.php');
        $this->assertSame(
            0,
            preg_match_all('/getenv\(\s*\'(?:NO_COLOR|CLICOLOR|FORCE_COLOR|CLICOLOR_FORCE)\'/', $legend),
            'the ladder decision must not be re-implemented anywhere but ColorLadder',
        );
    }

    /**
     * @param array<string,string> $vars env to set (others cleared)
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function withEnv(array $vars, callable $fn): mixed
    {
        $all = ['NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE', 'FORCE_COLOR'];
        foreach ($all as $k) {
            $this->envSave[$k] ??= getenv($k);
        }
        try {
            foreach ($all as $k) {
                if (isset($vars[$k])) {
                    putenv($k . '=' . $vars[$k]);
                } else {
                    putenv($k);
                }
            }
            return $fn();
        } finally {
            foreach ($all as $k) {
                $prev = $this->envSave[$k];
                if ($prev === false || $prev === null) {
                    putenv($k);
                } else {
                    putenv($k . '=' . $prev);
                }
            }
        }
    }
}
