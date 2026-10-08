<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\ProcRow;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Plot\Braille\DualSampleGraph;
use SugarCraft\Dash\Plot\Gradient101;
use SugarCraft\Dash\Plot\ProcRow\ProcColumns;
use SugarCraft\Dash\Plot\ProcRow\ProcGraphTracker;
use SugarCraft\Dash\Plot\ProcRow\ProcRow;
use SugarCraft\Dash\Plot\ProcRow\ProcRowComposer;
use SugarCraft\Dash\Plot\ProcRow\ProcRowPalette;

/**
 * Golden rows use btop's Default theme; every color below is worked by
 * hand from Proc::draw + generateGradients (truncating integer law):
 *
 *  - text fade, calc 2 / select_max 20 → proc[10]: 204 + trunc(-1400/100) = 190
 *  - cpu 37.5 → v 38, position 138 − 10 = 128 → process[28]:
 *      (128,208,163) + trunc(28·(92,1,−42)/50) = (179,208,140)
 *  - threads 12 → v 4, mem 4.2% → v 4: position 104 − 10 = 94 → proc_color[94]:
 *      (64,64,64) + trunc(94·(64,144,99)/100) = (124,199,157)
 *  - main_fg (204), inactive_fg (64), selected #6a2f2f / #eeeeee
 */
final class ProcRowComposerTest extends TestCase
{
    private const R = "\x1b[0m";
    private const B = "\x1b[1m";

    private static function fg(int $r, ?int $g = null, ?int $b = null): string
    {
        return "\x1b[38;2;{$r};" . ($g ?? $r) . ';' . ($b ?? $r) . 'm';
    }

    private static function bg(int $r, int $g, int $b): string
    {
        return "\x1b[48;2;{$r};{$g};{$b}m";
    }

    private static function proc(): ProcRow
    {
        return ProcRow::new(42, 'php', 'php artisan queue:work --tries=3', 12, 'joe', 37.5, 4.2);
    }

    /** Cold start: five fresh frames planted through the lifecycle helper. */
    private static function graph(): DualSampleGraph
    {
        $t = ProcGraphTracker::new();
        foreach ([10.0, 40.0, 80.0, 100.0, 0.0] as $cpu) {
            $t = $t->observe(42, $cpu);
        }
        return $t->graph(42);
    }

    public function testGoldenFadedRow(): void
    {
        $out = ProcRowComposer::new(78)->row(self::proc(), 0, 2, 20, self::graph(), false, ColorProfile::TrueColor);
        $text = self::fg(190);
        $cpu = self::fg(179, 208, 140);
        $metric = self::fg(124, 199, 157);
        $base = self::fg(204);
        $expected = self::R . $text . '      42 '
            . self::R . $cpu . 'php             '
            . self::R . $base . ' '
            . self::R . $text . 'php artisan queue '
            . self::R . $metric . '  12'
            . self::R . $base . ' '
            . self::R . $text . 'joe        '
            . self::R . $metric . ' 4.2%'
            . self::R . $base . ' '
            . self::R . self::fg(64) . '⣀⣀'
            . self::R . $cpu . '⢀⣼⡇'
            . self::R . $base . ' '
            . self::R . $cpu . '37.5'
            . self::R . $base . '  '
            . self::R;
        $this->assertSame($expected, $out);
        $this->assertSame(78, Width::string($out));
    }

    public function testGoldenSelectedRow(): void
    {
        // lc 1 with selected 2 is the selected row: highlight bar, bold
        // fields, graph still drawn (bold) over an un-dimmed underlay.
        $out = ProcRowComposer::new(78)->row(self::proc(), 1, 2, 20, self::graph(), false, ColorProfile::TrueColor);
        $on = self::R . self::bg(106, 47, 47) . self::fg(238) . self::B;
        $off = self::R . self::bg(106, 47, 47) . self::fg(238);
        $expected = $on . '      42 php             '
            . $off . ' '
            . $on . 'php artisan queue   12'
            . $off . ' '
            . $on . 'joe         4.2%'
            . $off . ' ⣀⣀'
            . $on . '⢀⣼⡇'
            . $off . ' '
            . $on . '37.5'
            . $off . '  '
            . self::R;
        $this->assertSame($expected, $out);
    }

    public function testFollowedRowUsesFollowedPair(): void
    {
        $out = ProcRowComposer::new(78)->row(self::proc(), 5, 2, 20, null, true, ColorProfile::TrueColor);
        $this->assertStringStartsWith(self::R . self::bg(64, 64, 181) . self::fg(238) . self::B . '      42', $out);
    }

    public function testRowBelowSelectionIsFullBrightness(): void
    {
        // btop's calc = |selected − lc| is 0 one row BELOW the bar.
        $out = ProcRowComposer::new(78)->row(self::proc(), 2, 2, 20, null, false, ColorProfile::TrueColor);
        $this->assertStringStartsWith(self::R . self::fg(204) . '      42 ', $out);
    }

    public function testGradientOffUsesMainFgAndFlatProcess(): void
    {
        $out = ProcRowComposer::new(78)->withProcGradient(false)
            ->row(self::proc(), 0, 2, 20, null, false, ColorProfile::TrueColor);
        // text = main_fg; cpu v 38 → process[38]: (128,208,163)+trunc(38·(92,1,−42)/50) = (197,208,132)
        $this->assertStringStartsWith(self::R . self::fg(204) . '      42 ' . self::R . self::fg(197, 208, 132) . 'php', $out);
    }

    public function testProcColorsOffBoldsMetricsInTextColor(): void
    {
        $out = ProcRowComposer::new(78)->withProcColors(false)
            ->row(self::proc(), 0, 2, 20, null, false, ColorProfile::TrueColor);
        $this->assertStringStartsWith(
            self::R . self::fg(190) . '      42 ' . self::R . self::fg(190) . self::B . 'php ',
            $out,
        );
    }

    public function testGoldenProcColorsOffTailIsBoldInactive(): void
    {
        // btop: with proc_colors off, c_color = Fx::b and end = Fx::ub, so
        // the inactive_fg emitted before the underlay is never reset —
        // graph glyphs and cpu% render bold #404040.
        $out = ProcRowComposer::new(78)->withProcColors(false)
            ->row(self::proc(), 0, 2, 20, self::graph(), false, ColorProfile::TrueColor);
        $inactive = self::fg(64);
        $this->assertStringEndsWith(
            self::R . self::fg(190) . self::B . ' 4.2%'
            . self::R . self::fg(204) . ' '
            . self::R . $inactive . '⣀⣀'
            . self::R . $inactive . self::B . '⢀⣼⡇'
            . self::R . $inactive . ' '
            . self::R . $inactive . self::B . '37.5'
            . self::R . $inactive . '  '
            . self::R,
            $out,
        );
        $this->assertSame(78, Width::string($out));
    }

    public function testProcColorsOffWithoutGraphsStillBoldInactiveCpu(): void
    {
        // btop emits inactive_fg outside the show_graphs ternary.
        $out = ProcRowComposer::new(78)->withWidth(78, false)->withProcColors(false)
            ->row(self::proc(), 0, 2, 20, null, false, ColorProfile::TrueColor);
        $inactive = self::fg(64);
        $this->assertStringEndsWith(
            self::R . $inactive . ' ' . self::R . $inactive . self::B . '37.5' . self::R . $inactive . '  ' . self::R,
            $out,
        );
        $this->assertSame(78, Width::string($out));
    }

    public function testNoGraphLeavesUnderlayAndFamilyPicksGlyph(): void
    {
        $c = ProcRowComposer::new(78);
        $plain = $c->row(self::proc(), 0, 2, 20, null, false, ColorProfile::NoTty);
        $this->assertStringContainsString(' 4.2% ⣀⣀⣀⣀⣀ 37.5  ', $plain);
        $tty = $c->withFamily(DualSampleGraph::FAMILY_TTY)->row(self::proc(), 0, 2, 20, null, false, ColorProfile::NoTty);
        $this->assertStringContainsString(' 4.2% ░░░░░ 37.5  ', $tty);
    }

    public function testWideGraphShowsNewestCells(): void
    {
        $g = DualSampleGraph::new(8, 1)->withData(100, 100, 100, 100, 100, 100, 0, 0, 0, 0, 100, 100, 100, 100, 100, 100);
        // Shown cells pair samples (1,2)…(15,16) by odd index: ⣿⣿⣿ blank
        // blank ⣿⣿⣿. The newest five keep the blanks (underlay) on the left;
        // the oldest five would end in them instead.
        $plain = ProcRowComposer::new(78)->row(self::proc(), 0, 2, 20, $g, false, ColorProfile::NoTty);
        $this->assertSame('⣿⣿⣿  ⣿⣿⣿', $g->render(ColorProfile::NoTty));
        $this->assertStringContainsString(' 4.2% ⣀⣀⣿⣿⣿ 37.5', $plain);
    }

    public function testNoTtyEmitsNoEscapes(): void
    {
        $plain = ProcRowComposer::new(78)->row(self::proc(), 0, 2, 20, self::graph(), false, ColorProfile::NoTty);
        $this->assertStringNotContainsString("\x1b", $plain);
        $this->assertSame('      42 php              php artisan queue   12 joe         4.2% ⣀⣀⢀⣼⡇ 37.5  ', $plain);
    }

    public function testControlCharactersCannotInjectEscapes(): void
    {
        $p = ProcRow::new(1, "evil\x1b[2J", "cmd\x07\x9b31m\u{009B}x\ttab", 1, "u\x1b]0;t\x07");
        $out = ProcRowComposer::new(78)->row($p, 0, 0, 20, null, false, ColorProfile::NoTty);
        $this->assertStringNotContainsString("\x1b", $out);
        $this->assertStringNotContainsString("\x07", $out);
        $this->assertStringNotContainsString("\u{009B}", $out);
        $this->assertSame(78, Width::string($out));
    }

    public function testMemLabelOverride(): void
    {
        $p = ProcRow::new(9, 'x', '', 1, 'u', 1.0, 50.0, '1.2G');
        $plain = ProcRowComposer::new(78)->row($p, 0, 0, 20, null, false, ColorProfile::NoTty);
        $this->assertStringContainsString(' 1.2G ', $plain);
    }

    public function testCustomColumnsPadSlack(): void
    {
        $c = ProcRowComposer::new(78)->withColumns(ProcColumns::new(6, 0, 0, 4, false));
        $plain = $c->row(ProcRow::new(1, 'abcdefgh', 'ignored', 3, 'rootuser', 120.0, 0.0), 0, 0, 20, null, false, ColorProfile::NoTty);
        $this->assertSame(
            '       1 abcdef roo+    0%   120  ' . str_repeat(' ', 78 - 34),
            $plain,
        );
    }

    /** @return iterable<string, array{int, bool, bool, bool}> */
    public static function widthCases(): iterable
    {
        foreach ([44, 50, 55, 56, 60, 70, 71, 74, 75, 80, 120, 200] as $box) {
            foreach ([true, false] as $graphs) {
                yield "box {$box} graphs " . ($graphs ? 'on' : 'off') . ' plain' => [$box, $graphs, false, false];
                yield "box {$box} graphs " . ($graphs ? 'on' : 'off') . ' selected' => [$box, $graphs, true, false];
                yield "box {$box} graphs " . ($graphs ? 'on' : 'off') . ' wide text' => [$box, $graphs, false, true];
            }
        }
    }

    #[DataProvider('widthCases')]
    public function testEveryRowIsExactlyTheInnerWidth(int $box, bool $graphs, bool $selected, bool $wide): void
    {
        $c = ProcRowComposer::new($box - 2)->withWidth($box - 2, $graphs);
        $p = $wide
            ? ProcRow::new(4_194_304, '日本語のプロセス名前', '漢字コマンド --フラグ=値 ' . str_repeat('長', 80), 123_456, 'ユーザー名前長い', 12_345.6, 99.99)
            : self::proc();
        foreach ([ColorProfile::NoTty, ColorProfile::TrueColor, ColorProfile::Ansi] as $profile) {
            $out = $c->row($p, 0, $selected ? 1 : 3, 20, self::graph(), false, $profile);
            $this->assertSame($box - 2, Width::string($out), "box {$box} {$profile->name}");
            $this->assertStringNotContainsString("\n", $out);
        }
    }

    /** @return array<string, array{float, string}> */
    public static function cpuLabelCases(): array
    {
        return [
            'zero' => [0.0, '0.0'],
            'single digit cut' => [5.678, '5.6'],
            'rounding carries into "10."' => [9.999, '10.'],
            'tens kept whole' => [12.5, '12.50'],
            'hundred' => [100.0, '100'],
            'hundreds cut' => [999.9, '999'],
            'thousands kept whole' => [1234.5, '1234.50'],
            'ten thousand' => [10_000.0, '10k'],
            'tens of thousands' => [12_345.0, '12k'],
            'hundreds of thousands' => [123_456.0, '123k'],
            'negative clamps' => [-4.0, '0.0'],
        ];
    }

    #[DataProvider('cpuLabelCases')]
    public function testCpuLabel(float $cpu, string $expected): void
    {
        $this->assertSame($expected, ProcRowComposer::cpuLabel($cpu));
    }

    public function testCpuColumnCutsLongLabels(): void
    {
        $plain = ProcRowComposer::new(78)->row(ProcRow::new(1, 'a', '', 1, 'u', 1234.5), 0, 0, 20, null, false, ColorProfile::NoTty);
        $this->assertStringEndsWith(' 1234  ', $plain);
        $plain = ProcRowComposer::new(78)->row(ProcRow::new(1, 'a', '', 1, 'u', 12.5), 0, 0, 20, null, false, ColorProfile::NoTty);
        $this->assertStringEndsWith(' 12.5  ', $plain);
    }

    /** @return array<string, array{float, string}> */
    public static function memLabelCases(): array
    {
        return [
            'zero' => [0.0, '0%'],
            'tiny' => [0.005, '0%'],
            'small' => [4.2, '4.2%'],
            'tens drop decimal' => [12.34, '12%'],
            'full' => [100.0, '100%'],
            'clamped' => [150.0, '100%'],
            'threshold' => [0.01, '0.0%'],
        ];
    }

    #[DataProvider('memLabelCases')]
    public function testMemLabel(float $pct, string $expected): void
    {
        $this->assertSame($expected, ProcRowComposer::memLabel($pct));
    }

    public function testThreadsAndUserLabels(): void
    {
        $this->assertSame('9999', ProcRowComposer::threadsLabel(9999));
        $this->assertSame('10K', ProcRowComposer::threadsLabel(10_000));
        $this->assertSame('123K', ProcRowComposer::threadsLabel(123_456));
        $this->assertSame('root', ProcRowComposer::userLabel('root', 10));
        $this->assertSame('verylongu+', ProcRowComposer::userLabel('verylongusername', 10));
    }

    public function testWithersAreImmutableAndAccessorsReflectState(): void
    {
        $a = ProcRowComposer::new(78);
        $b = $a->withProcColors(false)->withProcGradient(false)->withFamily('block')->withWidth(118);
        $this->assertTrue($a->procColors());
        $this->assertTrue($a->procGradient());
        $this->assertSame('braille', $a->family());
        $this->assertSame(78, $a->width());
        $this->assertFalse($b->procColors());
        $this->assertFalse($b->procGradient());
        $this->assertSame('block', $b->family());
        $this->assertSame(118, $b->width());
        $this->assertSame(16, $b->columns()->prog);

        $palette = ProcRowPalette::btop()->withSelected(Color::hex('#112233'), Color::hex('#ffffff'));
        $c = $a->withPalette($palette);
        $this->assertSame($palette, $c->palette());
        $this->assertNotSame($palette, $a->palette());
    }

    public function testRejectsBadInput(): void
    {
        $c = ProcRowComposer::new(78);
        foreach ([
            'selectMax 0' => fn () => $c->row(self::proc(), 0, 0, 0),
            'tall graph' => fn () => $c->row(self::proc(), 0, 0, 20, DualSampleGraph::new(5, 2)),
            'columns too wide' => fn () => $c->withColumns(ProcColumns::btop(120)),
            'box too narrow' => fn () => ProcRowComposer::new(30),
            'unknown family' => fn () => $c->withFamily('dots'),
        ] as $label => $call) {
            try {
                $call();
                $this->fail("{$label} accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCustomRampsDriveColors(): void
    {
        $red = Gradient101::expand([Color::rgb(0, 0, 0), Color::rgb(100, 0, 0)]);
        $palette = ProcRowPalette::btop()->withRamps($red, $red, $red);
        $out = ProcRowComposer::new(78)->withPalette($palette)
            ->row(self::proc(), 0, 2, 20, null, false, ColorProfile::TrueColor);
        // text fade index 10; cpu position 128 → process[28]
        $this->assertStringStartsWith(self::R . self::fg(10, 0, 0) . '      42 ' . self::R . self::fg(28, 0, 0) . 'php', $out);
    }
}
