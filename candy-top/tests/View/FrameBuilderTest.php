<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * calcSizes parity: every expected rectangle below is hand-derived from
 * btop_draw.cpp Draw::calcSizes (GPU build, zero gpus) and converted from
 * btop's 1-based (x, y) to 0-based.
 */
final class FrameBuilderTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, array<string, bool|string>, array<string, array{int,int,int,int}>}>
     */
    public static function layouts(): iterable
    {
        // cpu h=ceil(40*.32)=13; mem w=round(120*.45)=54, h=floor(40*.72)-13=15;
        // net h=40-13-15=12 at y=29; proc w=66 h=27 at x=55.
        yield 'default 120x40' => [120, 40, [], [
            'cpu' => [0, 0, 120, 13], 'mem' => [0, 13, 54, 15], 'net' => [0, 28, 54, 12], 'proc' => [54, 13, 66, 27],
        ]];
        // btop's minimum for all four boxes.
        yield 'default 80x24' => [80, 24, [], [
            'cpu' => [0, 0, 80, 8], 'mem' => [0, 8, 36, 9], 'net' => [0, 17, 36, 7], 'proc' => [36, 8, 44, 16],
        ]];
        yield 'alternate positions 120x40' => [120, 40, ['cpu_bottom' => true, 'mem_below_net' => true, 'proc_left' => true], [
            'cpu' => [0, 27, 120, 13], 'mem' => [66, 12, 54, 15], 'net' => [66, 0, 54, 12], 'proc' => [0, 0, 66, 27],
        ]];
        yield 'cpu+proc only' => [120, 40, ['shown_boxes' => 'cpu proc'], [
            'cpu' => [0, 0, 120, 13], 'proc' => [0, 13, 120, 27],
        ]];
        // no proc: mem/net take 100% width; no cpu: mem starts at row 0.
        yield 'mem+net only 100x30' => [100, 30, ['shown_boxes' => 'mem net'], [
            'mem' => [0, 0, 100, 21], 'net' => [0, 21, 100, 9],
        ]];
        yield 'cpu alone fills the screen' => [100, 30, ['shown_boxes' => 'cpu'], [
            'cpu' => [0, 0, 100, 30],
        ]];
        // 200x60: cpu ceil(19.2)=20, mem round(90)=90 x floor(43.2)-20=23, net 17, proc 110x40.
        yield 'large 200x60' => [200, 60, [], [
            'cpu' => [0, 0, 200, 20], 'mem' => [0, 20, 90, 23], 'net' => [0, 43, 90, 17], 'proc' => [90, 20, 110, 40],
        ]];
    }

    /**
     * @param array<string, bool|string> $options
     * @param array<string, array{int,int,int,int}> $expected
     */
    #[DataProvider('layouts')]
    public function testLayoutMatchesBtopCalcSizes(int $cols, int $rows, array $options, array $expected): void
    {
        $layout = FrameBuilder::layout($cols, $rows, self::config($options), 8);

        $actual = array_map(static fn (Rect $r): array => [$r->x, $r->y, $r->width, $r->height], $layout->boxes);
        ksort($actual);
        ksort($expected);
        $this->assertSame($expected, $actual);
    }

    public function testShownBoxesTileTheScreenWithoutOverlap(): void
    {
        foreach ([[80, 24], [120, 40], [133, 41], [200, 60], [97, 37]] as [$cols, $rows]) {
            foreach ([[], ['cpu_bottom' => true], ['proc_left' => true, 'mem_below_net' => true]] as $opts) {
                $layout = FrameBuilder::layout($cols, $rows, self::config($opts), 8);
                $covered = 0;
                $overlaps = [];
                foreach ($layout->boxes as $name => $r) {
                    $covered += $r->width * $r->height;
                    foreach ($layout->boxes as $other => $o) {
                        if ($other !== $name && !$r->intersect($o)->isEmpty()) {
                            $overlaps[] = "$name/$other";
                        }
                    }
                    $this->assertTrue($r->intersect(Rect::new(0, 0, $cols, $rows))->equals($r), "$name inside {$cols}x{$rows}");
                }
                $this->assertSame([], $overlaps, "{$cols}x{$rows}");
                $this->assertSame($cols * $rows, $covered, "{$cols}x{$rows} fully covered");
            }
        }
    }

    public function testCpuCoresSubBox(): void
    {
        // 8 cores, no temp: b_columns=max(2, ceil(9/8))=2; 2*21 < 120-40 so
        // column size 2, b_width=max(29, 41)=41; b_height=min(11, 4+4)=8;
        // b_x = 1+120-41-1 = 79, b_y = 1 + ceil(11/2) - ceil(8/2) + 1 = 4.
        $layout = FrameBuilder::layout(120, 40, Config::new(), 8);
        $this->assertSame([78, 3, 41, 8], self::rect($layout->cpuCores));
        $this->assertSame(2, $layout->coreColumns);
        $this->assertSame(2, $layout->coreColumnSize);

        // 48 cores + temp at 120x40: 7 columns don't fit any size, so
        // b_columns=(120-40)/14=5, size 0, b_width=14*5+1=71.
        $layout = FrameBuilder::layout(120, 40, Config::new(), 48, true);
        $this->assertSame([48, 1, 71, 11], self::rect($layout->cpuCores));
        $this->assertSame(5, $layout->coreColumns);
        $this->assertSame(0, $layout->coreColumnSize);
    }

    public function testMemDividerAndNetStatsBox(): void
    {
        $layout = FrameBuilder::layout(120, 40, Config::new(), 8);
        // mem_width = ceil((54-3)/2)=26 (+26%2=0); disks = 54-26-2 = 26.
        $this->assertSame(26, $layout->memWidth);
        $this->assertSame(26, $layout->disksWidth);
        $this->assertSame(26, $layout->memDivider);
        // net 54x12: b_width 27, b_height 9, b_x=1+54-27-1=27, b_y=29+5-4+1=31.
        $this->assertSame([26, 30, 27, 9], self::rect($layout->netStats));
        $this->assertSame(24, $layout->procSelectMax);

        $noDisks = FrameBuilder::layout(120, 40, self::config(['show_disks' => false]), 8);
        $this->assertNull($noDisks->memDivider);
        $this->assertSame(53, $noDisks->memWidth);

        // net 100x9 (no proc, no cpu): b_height = 9-2 = 7, b_y=22+3-3+1=23.
        $small = FrameBuilder::layout(100, 30, self::config(['shown_boxes' => 'mem net']), 8);
        $this->assertSame([72, 22, 27, 7], self::rect($small->netStats));
    }

    public function testMemOddWidthRoundsMemSideUpToEven(): void
    {
        // 100 cols: mem w=45, mem_width=ceil(42/2)=21 -> +1 = 22, disks=21.
        $layout = FrameBuilder::layout(100, 30, Config::new(), 4);
        $this->assertSame(22, $layout->memWidth);
        $this->assertSame(21, $layout->disksWidth);
    }

    /**
     * @return iterable<string, array{string, array{int,int}}>
     */
    public static function minimums(): iterable
    {
        yield 'all four' => ['cpu mem net proc', [80, 24]];
        yield 'cpu' => ['cpu', [60, 8]];
        yield 'proc' => ['proc', [44, 16]];
        yield 'mem net' => ['mem net', [36, 16]];
        yield 'cpu mem' => ['cpu mem', [60, 18]];
        yield 'net proc' => ['net proc', [80, 16]];
        yield 'cpu net' => ['cpu net', [60, 14]];
    }

    /** @param array{int,int} $expected */
    #[DataProvider('minimums')]
    public function testMinSizeMatchesBtopGetMinSize(string $boxes, array $expected): void
    {
        $this->assertSame($expected, FrameBuilder::minSize(explode(' ', $boxes)));
    }

    public function testFits(): void
    {
        $all = ['cpu', 'mem', 'net', 'proc'];
        $this->assertTrue(FrameBuilder::fits(80, 24, $all));
        $this->assertFalse(FrameBuilder::fits(79, 24, $all));
        $this->assertFalse(FrameBuilder::fits(80, 23, $all));
    }

    public function testClockBudget(): void
    {
        $this->assertSame(54, FrameBuilder::clockBudget(120, 120, false));
        $this->assertSame(32, FrameBuilder::clockBudget(120, 120, true));
        // battery reserve only on terminals >= 100 wide; floor of 10.
        $this->assertSame(14, FrameBuilder::clockBudget(80, 80, true));
        $this->assertSame(10, FrameBuilder::clockBudget(60, 60, false));
    }

    /** @param array<string, bool|string> $options */
    private static function config(array $options): Config
    {
        $config = Config::new();
        foreach ($options as $key => $value) {
            $config = $config->with($key, $value);
        }

        return $config;
    }

    public function testHotkeyHighlightsTheRealKeyNotTheFirstLetter(): void
    {
        $ink = Ink::new(ThemeConfig::new());
        $hi = $ink->fg('hi_fg');
        $title = $ink->fg('title');
        $plain = static function (string $markup): array {
            $s = Surface::new(20, 1);
            $s->ansi(0, 0, $markup);
            $styles = [];
            for ($x = 0; $x < 20; $x++) {
                $styles[] = $s->style($x, 0);
            }

            return [rtrim($s->plainLines()[0]), $styles];
        };

        // Key mid-label (case-insensitive): only that letter is lit.
        [$text, $styles] = $plain(FrameBuilder::hotkey('Voreinstellung', 'p', $ink));
        $this->assertSame('p Voreinstellung', $text, 'key absent from the label is rendered in front');
        $this->assertSame(Surface::canonical($hi), $styles[0]);
        $this->assertSame(Surface::canonical($title), $styles[2]);

        [$text, $styles] = $plain(FrameBuilder::hotkey('Hauptmenü', 'm', $ink));
        $this->assertSame('Hauptmenü', $text);
        $this->assertSame(Surface::canonical($title), $styles[0]);
        $this->assertSame(Surface::canonical($hi), $styles[5], 'the m, not the H');
        $this->assertSame(Surface::canonical($title), $styles[6]);

        [$text, $styles] = $plain(FrameBuilder::hotkey('Disks', 'd', $ink));
        $this->assertSame('Disks', $text);
        $this->assertSame(Surface::canonical($hi), $styles[0], 'case-insensitive match');
        $this->assertSame(Surface::canonical($title), $styles[1]);
    }

    /** @return array{int,int,int,int} */
    private static function rect(?Rect $r): array
    {
        self::assertNotNull($r);

        return [$r->x, $r->y, $r->width, $r->height];
    }
}
