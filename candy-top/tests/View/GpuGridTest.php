<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Config\GpuPanels;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\GpuDetail;
use SugarCraft\Top\View\GpuGrid;
use SugarCraft\Top\View\GpuRoster;

/**
 * btop PR #1881's gpu grid and its calcSizes / get_min_size integration,
 * with PR #1730's slot titles and selector zones. Expected numbers are
 * worked by hand from the PR's formulas (comments show the arithmetic).
 */
final class GpuGridTest extends TestCase
{
    /** Two NVIDIA-shaped GPUs (offset 8: util + pwr + enc/dec + 5 memory rows) and an NPU (offset 2). */
    private static function roster(): GpuRoster
    {
        return new GpuRoster([8, 8, 2], ['RTX 4090', 'RTX 4080', 'NPU'], 2);
    }

    private static function config(string $boxes, array $options = []): Config
    {
        $config = Config::new()->with('shown_boxes', $boxes);
        foreach ($options as $k => $v) {
            $config = $config->with($k, $v);
        }

        return $config;
    }

    #[DataProvider('columns')]
    public function testColumns(int $boxes, int $width, ?int $forced, int $expected): void
    {
        $this->assertSame($expected, GpuGrid::columns($boxes, $width, $forced));
    }

    /** @return iterable<string, array{int, int, ?int, int}> */
    public static function columns(): iterable
    {
        yield 'no boxes' => [0, 200, null, 1];
        yield 'auto: 160 / 35 = 4, capped by 2 boxes' => [2, 160, null, 2];
        yield 'auto: 100 / 35 = 2' => [3, 100, null, 2];
        yield 'auto: narrow terminal still 1' => [3, 20, null, 1];
        yield 'forced 1' => [3, 200, 1, 1];
        yield 'forced 6 capped by the width' => [6, 140, 6, 4];
        yield 'forced beyond 6 clamps' => [6, 400, 9, 6];
        yield 'forced 0 clamps to 1' => [2, 200, 0, 1];
    }

    public function testDetailLevelsAndStatsWidths(): void
    {
        $this->assertSame(GpuDetail::Full, GpuDetail::forWidth(56));
        $this->assertSame(GpuDetail::Compact, GpuDetail::forWidth(55));
        $this->assertSame(GpuDetail::Compact, GpuDetail::forWidth(44));
        $this->assertSame(GpuDetail::Minimal, GpuDetail::forWidth(43));
        $this->assertSame(41, GpuDetail::Full->panelWidth(60), 'clamp(30, 41, 55)');
        $this->assertSame(55, GpuDetail::Full->panelWidth(160));
        $this->assertSame(26, GpuDetail::Compact->panelWidth(44));
        $this->assertSame(24, GpuDetail::Minimal->panelWidth(34));
        $this->assertSame(32, GpuDetail::Minimal->panelWidth(43));
        $this->assertSame([12, 7, 5], [GpuDetail::Full->height(8), GpuDetail::Compact->height(8), GpuDetail::Minimal->height(8)]);
        $this->assertSame([6, 6, 5], [GpuDetail::Full->height(2), GpuDetail::Compact->height(2), GpuDetail::Minimal->height(2)]);
    }

    public function testMinTotals(): void
    {
        $this->assertSame(0, GpuGrid::minWidth(0, 120, null));
        // 2 boxes at 120 columns sit side by side: 2 * 34 + 1.
        $this->assertSame(69, GpuGrid::minWidth(2, 120, null));
        $this->assertSame(34, GpuGrid::minWidth(2, 120, 1));
        // btop walks EVERY detected offset: at 120 / 2 boxes the box is 59 wide (Full), the tallest needs 12.
        $this->assertSame(12, GpuGrid::minHeight([2], 120, 2, self::roster()));
        $this->assertSame(24, GpuGrid::minHeight([0, 1], 120, 1, self::roster()), 'two rows');
        // Nothing detected yet: the shown boxes' default offset (4); 60 wide is Full -> 8 rows, 40 Minimal -> 5.
        $this->assertSame(8, GpuGrid::minHeight([3], 60, null, GpuRoster::none()));
        $this->assertSame(5, GpuGrid::minHeight([3], 40, null, GpuRoster::none()));
        $this->assertSame(0, GpuGrid::minHeight([], 60, null, self::roster()));
    }

    public function testPrepassIsBtopsProvisionalTotalHeight(): void
    {
        $this->assertSame([1, 0, 0], GpuGrid::prepass([], 160, null, self::roster()));
        // 3 boxes at 100 columns: 2 per row, 2 rows, tallest 4 + 8.
        $this->assertSame([2, 2, 24], GpuGrid::prepass([0, 2, 1], 100, null, self::roster()));
        $this->assertSame([1, 1, 6], GpuGrid::prepass([2], 100, null, self::roster()), 'the NPU alone: 4 + 2');
    }

    public function testCpuGpusAndTheRestShareTheHeight(): void
    {
        $layout = FrameBuilder::layout(160, 50, self::config('cpu gpu0 gpu1 mem net proc'), 8, false, self::roster());
        // cpu: 32 / (1 gpu row + 1) + 5 = 21 % of 50 -> ceil(10.5) = 11; Auto lists no GPU (both boxed).
        $this->assertSame([0, 0, 160, 11], self::rect($layout->box('cpu')));
        $this->assertSame(['cpu', 'gpu0', 'gpu1', 'mem', 'net', 'proc'], array_keys($layout->ordered()));
        $this->assertSame(2, $layout->gpuColumns);
        $this->assertSame(11, $layout->gpuHeight, 'box height = the cpu height, under the 12 rows Full needs');
        $gpu0 = $layout->gpuBox('gpu0');
        $gpu1 = $layout->gpuBox('gpu1');
        $this->assertNotNull($gpu0);
        $this->assertNotNull($gpu1);
        // (160 - 1) / 2 = 79 wide, one blank column between, stats clamp(39, 41, 55) = 41 wide.
        $this->assertSame([0, 11, 79, 11], self::rect($gpu0->rect));
        $this->assertSame([80, 11, 79, 11], self::rect($gpu1->rect));
        $this->assertSame(GpuDetail::Full, $gpu0->detail);
        // stats: x = 79 - 41 - 1, height min(11 - 2, 12 - 2), y = 11 + ceil(0 / 2) + 1.
        $this->assertSame([37, 12, 41, 9], self::rect($gpu0->stats));
        $this->assertSame(9, $gpu0->graphHeight);
        $this->assertSame([0, 0, 0], [$gpu0->index, $gpu0->panel, $gpu0->slot]);
        $this->assertSame([1, 1, 1, 6], [$gpu1->index, $gpu1->panel, $gpu1->slot, $gpu1->key()]);
        // `← gpu0 →`: 6 + 4 wide, opening junction at 79 - 10 - 2 = 67.
        $this->assertSame([68, 75], $gpu0->selector);
        $this->assertSame(['gpu0', 'RTX 4090', false], [$gpu0->label, $gpu0->deviceName, $gpu0->npu]);
        // mem: net share 28 * 4 / 5 = 22 -> floor(50 * 78 / 100) - 11 - 11 = 17, below the grid.
        $this->assertSame([0, 22, 72, 17], self::rect($layout->box('mem')));
        $this->assertSame([72, 22, 88, 28], self::rect($layout->box('proc')));
        $this->assertSame(39, $layout->box('net')?->y);
    }

    public function testGpuBoxesAloneFillTheirRows(): void
    {
        $layout = FrameBuilder::layout(100, 30, self::config('gpu0 gpu2'), 8, false, self::roster());
        // Only gpu boxes, (100 - 1) / 2 = 49 wide (Compact): max(8, 30 / 1 row)
        // capped at the 7 rows Compact needs (min(8, 3) + 4), floored at 8.
        $gpu = $layout->gpuBox('gpu0');
        $this->assertNotNull($gpu);
        $this->assertSame([0, 0, 49, 8], self::rect($gpu->rect));
        $this->assertSame([22, 2, 26, 5], self::rect($gpu->stats), 'min(6, 7 - 2) rows at 49 - 26 - 1, y = ceil(1 / 2) + 1');
        $npu = $layout->gpuBox('gpu2');
        $this->assertNotNull($npu);
        $this->assertSame(['npu0', true], [$npu->label, $npu->npu]);
        $this->assertSame([50, 0, 49, 8], self::rect($npu->rect));
        $this->assertSame(GpuDetail::Compact, $npu->detail);
        // The NPU's stats box: min(6, max(2, min(2, 3) + 4 - 2)) = 4 rows, centred: 0 + ceil(2 / 2) + 1.
        $this->assertSame([72, 2, 26, 4], self::rect($npu->stats));
    }

    public function testForcedColumnsStackRows(): void
    {
        $config = self::config('gpu0 gpu1 proc', ['gpu_box_columns' => '1']);
        $layout = FrameBuilder::layout(100, 40, $config, 8, false, self::roster());
        // Two rows; with proc shown: max(8, ceil(40 * 32 / 2 / 100)) = 8.
        $this->assertSame([0, 0, 100, 8], self::rect($layout->gpuBox('gpu0')?->rect));
        $this->assertSame([0, 8, 100, 8], self::rect($layout->gpuBox('gpu1')?->rect));
        $this->assertSame([0, 16, 100, 24], self::rect($layout->box('proc')));
        $this->assertSame(1, $layout->gpuColumns);
    }

    public function testCpuWithOnlyGpuBoxes(): void
    {
        $layout = FrameBuilder::layout(120, 40, self::config('cpu gpu0'), 8, false, self::roster());
        // btop: cpu = 40 - 12 (provisional grid) - 1 (GPU1 listed in the cpu box), + 1 back.
        $this->assertSame([0, 0, 120, 28], self::rect($layout->box('cpu')));
        // The gpu box is btop's min_height 8 when cpu is the only other box.
        $this->assertSame([0, 28, 120, 8], self::rect($layout->gpuBox('gpu0')?->rect));
    }

    public function testCpuBottomPutsTheGridOnTop(): void
    {
        $layout = FrameBuilder::layout(160, 50, self::config('cpu gpu0 mem net proc', ['cpu_bottom' => true]), 8, false, self::roster());
        $this->assertSame(0, $layout->gpuBox('gpu0')?->rect->y);
        $cpu = $layout->box('cpu');
        $this->assertNotNull($cpu);
        $this->assertSame(50, $cpu->bottom());
        $this->assertSame($layout->gpuHeight, $layout->box('proc')?->y);
    }

    public function testCpuBoxGrowsByTheGpusItLists(): void
    {
        $base = FrameBuilder::layout(120, 40, self::config('cpu mem net proc'), 8, false);
        $this->assertSame(13, $base->box('cpu')?->height, 'ceil(40 * 32 %)');
        // Auto: both GPUs listed in the cpu box -> btop gpus_extra_height = 2.
        $auto = FrameBuilder::layout(120, 40, self::config('cpu mem net proc'), 8, false, self::roster());
        $this->assertSame(15, $auto->box('cpu')?->height);
        $this->assertSame(($base->cpuCores?->height ?? 0) + 2, $auto->cpuCores?->height);
        $off = FrameBuilder::layout(120, 40, self::config('cpu mem net proc', ['show_gpu_info' => 'Off']), 8, false, self::roster());
        $this->assertSame(13, $off->box('cpu')?->height);
        $on = FrameBuilder::layout(160, 50, self::config('cpu gpu0 mem net proc', ['show_gpu_info' => 'On']), 8, false, self::roster());
        $autoBoxed = FrameBuilder::layout(160, 50, self::config('cpu gpu0 mem net proc'), 8, false, self::roster());
        $this->assertSame(2, ($on->box('cpu')?->height ?? 0) - ($autoBoxed->box('cpu')?->height ?? 0) + 1, 'On lists both GPUs, Auto only the unboxed one');
    }

    public function testMinSizeCountsTheGrid(): void
    {
        $all = ['cpu', 'gpu0', 'mem', 'net', 'proc'];
        // 80 wide: one box per row, Full (80 >= 56) -> the tallest detected need, 12.
        $this->assertSame([80, 36], FrameBuilder::minSize($all, 80, self::roster()));
        $this->assertFalse(FrameBuilder::fits(80, 35, $all, self::roster()));
        $this->assertTrue(FrameBuilder::fits(80, 36, $all, self::roster()));
        // gpu boxes alone: two side by side need 69 columns; 49-wide boxes are Compact: 7 rows.
        $this->assertSame([69, 7], FrameBuilder::minSize(['gpu0', 'gpu1'], 100, self::roster()));
        // A gpuN beyond the detected accelerators is not counted (btop PR #1730).
        $this->assertSame([0, 0], FrameBuilder::minSize(['gpu9'], 100, self::roster()));
        $this->assertSame([34, 5], FrameBuilder::minSize(['gpu9'], 0, GpuRoster::none()), 'nothing detected: counted at the default');
        $this->assertSame(FrameBuilder::minSize(['cpu', 'mem', 'net', 'proc']), FrameBuilder::minSize(['cpu', 'mem', 'net', 'proc'], 80, self::roster()));
    }

    public function testSlotTitlesFollowTheStoredSlots(): void
    {
        $config = GpuPanels::withBoxes(Config::new(), ['cpu', 'gpu1', 'gpu0', 'mem', 'net', 'proc'], [5, 2]);
        $layout = FrameBuilder::layout(160, 50, $config, 8, false, self::roster());
        $this->assertSame(['gpu0', 'gpu1'], array_keys($layout->gpuBoxes), 'slot order');
        $this->assertSame([7, 0], [$layout->gpuBox('gpu0')?->key(), $layout->gpuBox('gpu1')?->key()]);
    }

    public function testRosterHelpers(): void
    {
        $r = self::roster();
        $this->assertSame([3, 2], [$r->count(), $r->gpuCount()]);
        $this->assertSame([8, 2, 4], [$r->offset(0), $r->offset(2), $r->offset(7)]);
        $this->assertSame(['RTX 4080', ''], [$r->name(1), $r->name(9)]);
        $this->assertSame([false, true, false], [$r->isNpu(1), $r->isNpu(2), $r->isNpu(3)]);
        $this->assertSame(['gpu1', 'npu0'], [$r->label(1), $r->label(2)]);
        $this->assertTrue($r->equals(self::roster()));
        $this->assertFalse($r->equals(GpuRoster::none()));
        $this->assertSame(0, GpuRoster::none()->count());
    }

    public function testNoSelectorWithOneAcceleratorOrANarrowBox(): void
    {
        $one = new GpuRoster([8], ['RTX'], 1);
        $this->assertNull(FrameBuilder::layout(120, 40, self::config('gpu0 proc'), 8, false, $one)->gpuBox('gpu0')?->selector);
        [$boxes] = GpuGrid::place(['gpu0'], [0], 19, 40, null, self::roster(), 0, false, false, true);
        $this->assertNotNull($boxes['gpu0']->selector, 'the 34-wide minimum box still fits it');
    }

    /** @return array{0: int, 1: int, 2: int, 3: int}|null */
    private static function rect(?\SugarCraft\Top\View\Rect $r): ?array
    {
        return $r === null ? null : [$r->x, $r->y, $r->width, $r->height];
    }
}
