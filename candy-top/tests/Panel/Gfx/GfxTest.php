<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gfx;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Dash\Plot\Chart\Meter;
use SugarCraft\Top\Collect\Freq;
use SugarCraft\Top\Panel\Gfx\History;
use SugarCraft\Top\Panel\Gfx\Just;
use SugarCraft\Top\Panel\Net\Humanizer;
use SugarCraft\Top\Panel\Gfx\NamedSources;
use SugarCraft\Top\Panel\Gfx\PositionMeter;
use SugarCraft\Top\Panel\Gfx\TintedGraph;
use SugarCraft\Top\Source\Fake\FakeFreq;
use SugarCraft\Top\Source\Fake\FakeTemp;
use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\Theme\TtyTheme;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Surface;

final class GfxTest extends TestCase
{
    /** The cpu/mem views share P-C's btop floating_humanizer; the byte forms they print, digit for digit. */
    #[DataProvider('humanized')]
    public function testHumanizer(int|float $bytes, bool $shorten, bool $base10, string $expected): void
    {
        $this->assertSame($expected, Humanizer::new($base10)->format($bytes, $shorten));
    }

    /** @return iterable<string, array{int|float, bool, bool, string}> */
    public static function humanized(): iterable
    {
        yield 'zero' => [0, false, false, '0 Byte'];
        yield 'negative sentinel' => [-1, false, false, '0 Byte'];
        yield 'bytes' => [1023, false, false, '1023 Byte'];
        yield '1 KiB' => [1024, false, false, '1.00 KiB'];
        yield '16 GiB' => [16 * 1024 ** 3, false, false, '16.0 GiB'];
        yield '204 MiB' => [214_748_364, false, false, '204 MiB'];
        yield 'truncates, never rounds' => [6_227_702_579, false, false, '5.79 GiB'];
        yield 'short 8 GiB' => [8 * 1024 ** 3, true, false, '8.0G'];
        yield 'short 24 GiB' => [24 * 1024 ** 3, true, false, '24G'];
        yield 'short 1000 MiB rolls to 1.0G' => [1000 * 1024 ** 2, true, false, '1.0G'];
        yield 'base10 drops the decimal at 4 digits' => [16_000_000_000, false, true, '16 GB'];
        yield 'base10 999 kB' => [999_000, false, true, '999 kB'];
    }

    public function testPerSecondSuffix(): void
    {
        $this->assertSame('1.00 KiB/s', Humanizer::new()->format(1024, perSecond: true));
    }

    public function testJustTruncatesLikeBtop(): void
    {
        $this->assertSame('   42', Just::right('42', 5));
        $this->assertSame('8.94 Gi', Just::right('8.94 GiB', 7));
        $this->assertSame('Avail', Just::left('Available', 5));
        $this->assertSame('U', Just::left('Used', 1));
        $this->assertSame('ab   ', Just::left('ab', 5));
        $this->assertSame('', Just::right('x', 0), 'btop resize(0)');
        $this->assertSame('', Just::left('x', 0));
        $this->assertSame('16.0 GiB', Just::right('16.0 GiB', -3), 'negative = size_t wrap: untruncated');
        $this->assertSame('Avail', Just::left('Avail', -1));
    }

    /** btop #1008 at the series level. */
    public function testHistoryWithoutPrefixesDropsWholeSeriesFamilies(): void
    {
        $h = History::new()->push('gpu:1:a', 5, 4)->push('gpu:10:a', 6, 4)->push('gpu:1:b', 7, 4)->push('total', 8, 4);
        $this->assertSame(['gpu:10:a', 'total'], $h->withoutPrefixes('gpu:1:')->keys(), '`gpu:1:` never matches `gpu:10:`');
        $this->assertSame(['total'], $h->withoutPrefixes('gpu:1:', 'gpu:10:')->keys());
        $this->assertSame($h->keys(), $h->withoutPrefixes()->keys());
        $this->assertSame(['gpu:1:a', 'gpu:10:a', 'gpu:1:b', 'total'], $h->keys(), 'immutable');
    }

    public function testHistoryHoldsCapsAndRounds(): void
    {
        $h = History::new();
        $this->assertSame($h, $h->push('a', -1.0, 5), 'never-measured stays empty');
        $this->assertNull($h->last('a'));
        $this->assertFalse($h->has('a'));
        $h = $h->push('a', 10.4, 3)->push('a', -1.0, 3)->push('a', NAN, 3)->push('a', 20.6, 3);
        $this->assertSame([10, 10, 21], $h->series('a'));
        $this->assertSame(21, $h->last('a'));
        $this->assertTrue($h->has('a'));
        $this->assertSame(['a'], $h->keys());
        $this->assertSame([], History::new()->series('zzz'));
    }

    /** PositionMeter is the dash position-mode Meter, resolved through Ink. */
    public function testPositionMeterMatchesTheDashMeterCellForCell(): void
    {
        $palette = ThemeConfig::new();
        $ink = Ink::new($palette, ColorProfile::TrueColor);
        $stops = array_map(static fn (int $i): Color => $palette->at('cpu', $i), range(0, 100));
        foreach ([[1, 0], [1, 100], [10, 45], [33, 70], [40, 100], [17, 0]] as [$width, $value]) {
            foreach ([false, true] as $invert) {
                $dash = Meter::new($value / 100)->withWidth($width)->withGradient($stops, true)->withInvert($invert)->withMeterBg($palette->color('meter_bg'))->render();
                $mine = PositionMeter::render($ink, $width, $value, 'cpu', $invert);
                $a = Surface::new($width, 1);
                $b = Surface::new($width, 1);
                $a->ansi(0, 0, $dash);
                $b->ansi(0, 0, $mine);
                $this->assertSame($a->lines(), $b->lines(), "width {$width} value {$value} invert " . var_export($invert, true));
            }
        }
        $this->assertSame('', PositionMeter::render($ink, 0, 50, 'cpu'));
    }

    public function testPositionMeterHonoursTheTtyProfile(): void
    {
        $out = PositionMeter::render(Ink::new(TtyTheme::new(), ColorProfile::Ansi), 10, 60, 'cpu');
        $this->assertStringNotContainsString('38;2;', $out);
        $this->assertSame(10, mb_substr_count($out, '■'));
    }

    /** In TrueColor the tinted bytes equal the graph coloured with the palette's own ramp. */
    public function testTintedGraphEqualsThePaletteRampInTrueColor(): void
    {
        $palette = ThemeConfig::new();
        $ink = Ink::new($palette, ColorProfile::TrueColor);
        $stops = array_map(static fn (int $i): Color => $palette->at('cpu', $i), range(0, 100));
        foreach ([[12, 3, false], [12, 1, false], [8, 4, true]] as [$w, $h, $invert]) {
            $graph = DualSampleGraph::new($w, $h, 'braille', $invert)->withData(0, 15, 40, 90, 100, 60, 33, 5, 72, 18);
            $this->assertSame(
                explode("\n", $graph->withGradient($stops)->render(ColorProfile::TrueColor)),
                TintedGraph::lines($graph, $ink, 'cpu'),
            );
        }
    }

    public function testTransSkipsSpaces(): void
    {
        $surface = Surface::new(8, 1);
        $surface->put(0, 0, '--------');
        $this->assertSame(7, \SugarCraft\Top\Panel\Gfx\Trans::put($surface->region(\SugarCraft\Top\View\Rect::new(0, 0, 8, 1)), 1, 0, '1d 0 :0'));
        $this->assertSame(['-1d-0-:0'], $surface->plainLines());
    }

    public function testTintedGraphUnderlayAndTtyProfile(): void
    {
        $graph = DualSampleGraph::new(6, 1, 'tty')->withData(0, 0, 50);
        $ink = Ink::new(TtyTheme::new(), ColorProfile::Ansi);
        $line = TintedGraph::lines($graph, $ink, 'temp', true)[0];
        $this->assertStringNotContainsString('38;2;', $line);
        $this->assertStringContainsString($ink->fg('inactive_fg') . '░', $line, 'underlay in inactive_fg');
        $this->assertSame('cached', TintedGraph::gradientName($ink, ['zswap', 'cached', 'used']));
        $this->assertSame('cpu', TintedGraph::gradientName($ink, ['nope']));
    }

    public function testNamedSourcesSampleAndRecompose(): void
    {
        $set = NamedSources::of(['freq' => FakeFreq::new(2), 'temp' => FakeTemp::new(1), 'none' => null]);
        $this->assertSame(['freq', 'temp'], $set->names());
        [$samples, $next] = $set->sample();
        $this->assertInstanceOf(Samples::class, $samples);
        $this->assertSame(['freq', 'temp'], array_keys($samples->snapshots));
        $this->assertInstanceOf(NamedSources::class, $next);
        $this->assertSame(['temp'], $set->only(['temp', 'missing'])->names());
        $this->assertSame(['freq'], $set->with('temp', null)->names());
        $this->assertNull($set->get('nope'));
        $merged = $set->merge(NamedSources::of(['temp' => FakeTemp::new(3), 'gpu' => FakeTemp::new(0)]));
        $this->assertSame(['freq', 'temp', 'gpu'], $merged->names());
        $this->assertCount(3, $merged->get('temp')->sample()[0]->cores);
    }

    public function testFakeFreqAndTempAreDeterministic(): void
    {
        $this->assertEquals(FakeFreq::new(4)->sample()[0], FakeFreq::new(4)->sample()[0]);
        [$off] = FakeFreq::new(4)->sample();
        $this->assertSame([], $off->perCore);
        $this->assertSame(Freq::label($off->mhz), $off->label);
        [$on, $next] = FakeFreq::new(4, true)->sample();
        $this->assertCount(4, $on->perCore);
        $this->assertSame([800.0, 800.0, 800.0, 800.0], $on->perCoreMin);
        $this->assertNotEquals($on, $next->sample()[0]);
        $this->assertSame(FakeFreq::new(2)->withPerCore(false)->perCore(), false);

        [$temp] = FakeTemp::new(4)->sample();
        $this->assertSame('coretemp/Package id 0', $temp->cpuSensor);
        $this->assertCount(4, $temp->cores);
        $this->assertSame(100.0, $temp->cpuCrit());
        $this->assertEquals($temp, FakeTemp::new(4)->sample()[0]);
    }
}
