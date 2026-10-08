<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Top\Collect\GpuProcess;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\PanelResult;
use SugarCraft\Top\Panel\Proc\ProcGpuSample;
use SugarCraft\Top\Panel\Proc\ProcView;
use SugarCraft\Top\Panel\Gpu\GpuFeed;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Source\Fake\FakeGpuProcesses;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Source\Source;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Layout;
use SugarCraft\Top\View\Rect;
use SugarCraft\Top\View\Surface;

/**
 * btop #1552 in the proc box: the GPU source sampled with the process
 * list, the per-pid join and its hold, the `g` / `ctrl+g` / button
 * toggles, the GPU mini-graphs, and paint goldens of the GMem / Gpu%
 * columns. Regenerate the goldens with
 * `CANDY_TOP_UPDATE_GOLDENS=1 vendor/bin/phpunit tests/Panel/ProcPanelGpuTest.php`;
 * a missing golden fails.
 */
final class ProcPanelGpuTest extends TestCase
{
    private const DIR = __DIR__ . '/../fixtures/panels/proc-gpu/';

    private Config $config;
    private Layout $layout;
    private Rect $box;

    protected function setUp(): void
    {
        $this->resize(180, 30, ['shown_boxes' => 'proc']);
    }

    /** @param array<string, bool|int|string> $options */
    private function resize(int $cols, int $rows, array $options = []): void
    {
        $this->config = Config::new();
        foreach ($options as $k => $v) {
            $this->config = $this->config->with($k, $v);
        }
        $this->layout = FrameBuilder::layout($cols, $rows, $this->config, 8);
        $box = $this->layout->box('proc');
        $this->assertNotNull($box);
        $this->box = $box;
    }

    private function ctx(): PanelContext
    {
        return new PanelContext($this->config, $this->layout, $this->box);
    }

    private function apply(PanelResult $r): ProcPanel
    {
        foreach ($r->set as $k => $v) {
            $this->config = $this->config->with($k, $v);
        }
        $this->assertInstanceOf(ProcPanel::class, $r->panel);

        return $r->panel;
    }

    private function sample(ProcPanel $p, int $n = 1): ProcPanel
    {
        for ($i = 0; $i < $n; $i++) {
            $cmd = $p->collect($this->ctx());
            $this->assertNotNull($cmd);
            $p = $this->apply($p->update($cmd(), $this->ctx()));
        }

        return $p;
    }

    private function key(ProcPanel $p, KeyMsg $msg): ProcPanel
    {
        return $this->apply($p->update($msg, $this->ctx()));
    }

    /**
     * A GPU feed replaying `$snapshots` (the last one repeats).
     *
     * @param list<GpuSnapshot> $snapshots
     */
    private static function scripted(array $snapshots): Source
    {
        return new class ($snapshots) implements Source {
            /** @param list<GpuSnapshot> $snapshots */
            public function __construct(private readonly array $snapshots)
            {
            }

            public function sample(): array
            {
                return [$this->snapshots[0], new self(count($this->snapshots) > 1 ? array_slice($this->snapshots, 1) : $this->snapshots)];
            }
        };
    }

    private function demo(?Source $gpu = null): ProcPanel
    {
        return $this->sample(ProcPanel::new(FakeProcList::demo(8))->withGpu($gpu ?? FakeGpuProcesses::demo()), 3);
    }

    /** @return array<int, \SugarCraft\Top\Panel\Proc\ProcEntry> pid => row */
    private function byPid(ProcPanel $p): array
    {
        $out = [];
        foreach ($p->rows($this->config) as $row) {
            $out[$row->pid()] = $row;
        }

        return $out;
    }

    // ---- sampling + join ----------------------------------------------------------

    public function testCollectSamplesTheGpuWithTheProcessList(): void
    {
        $p = ProcPanel::new(FakeProcList::demo(8))->withGpu(FakeGpuProcesses::demo());
        $msg = ($p->collect($this->ctx()))();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertInstanceOf(ProcGpuSample::class, $msg->snapshot);
        $this->assertInstanceOf(FakeProcList::class, $msg->next, 'next stays the process source');
        $this->assertInstanceOf(GpuFeed::class, $msg->snapshot->gpuNext, 'a source of its own is wrapped in a feed');
        $this->assertInstanceOf(FakeGpuProcesses::class, $msg->snapshot->gpuNext->source());

        $p = $this->apply($p->update($msg, $this->ctx()));
        $this->assertTrue($p->gpuAvailable());
        $this->assertSame($msg->snapshot->gpuNext, $p->gpuSource(), 'the GPU source is carried forward');
        $rows = $this->byPid($p);
        $this->assertGreaterThan(20.0, $rows[5133]->gpu);
        $this->assertSame($rows[5133]->gpu, $p->gpuUsage()->utilization(5133));
        $this->assertGreaterThan(6 * 1024 ** 3, $rows[5133]->gpuMem);
        $this->assertSame(4 * 1024 ** 3, $rows[6969]->gpuMem, 'summed over both GPUs');
        $this->assertSame(0.0, $rows[880]->gpu, 'listed without utilization');
        $this->assertSame(512 * 1024 ** 2, $rows[880]->gpuMem);
        $this->assertSame([0.0, 0], [$rows[1]->gpu, $rows[1]->gpuMem], 'a pid no GPU lists reads 0');
    }

    public function testWithoutAGpuSourceNothingChanges(): void
    {
        $p = $this->sample(ProcPanel::new(FakeProcList::demo(8)), 2);
        $msg = ($p->collect($this->ctx()))();
        $this->assertInstanceOf(ProcSnapshot::class, $msg->snapshot);
        $this->assertFalse($p->gpuAvailable());
        $this->assertNull($p->gpuSource());
        $this->assertSame(['gpu' => -1, 'gmem' => -1], array_intersect_key(ProcView::sizes($this->box->width, true, $p->gpuAvailable()), ['gpu' => 0, 'gmem' => 0]));
    }

    public function testANarrowBoxDoesNotSampleTheGpuAndDropsItsValues(): void
    {
        $p = $this->demo();
        $this->resize(120, 40); // default layout: the proc box is 66 wide
        $msg = ($p->collect($this->ctx()))();
        $this->assertInstanceOf(ProcSnapshot::class, $msg->snapshot, 'no column fits: no nvidia-smi spawn');
        $p = $this->apply($p->update($msg, $this->ctx()));
        $this->assertTrue($p->gpuAvailable(), 'availability is a host property');
        $this->assertSame([], $p->gpuUsage()->pids());
        $this->assertSame(0.0, $this->byPid($p)[5133]->gpu);

        $this->config = $this->config->with('proc_sorting', 'gpu');
        $this->assertInstanceOf(ProcGpuSample::class, ($p->collect($this->ctx()))()->snapshot, 'a gpu sort needs the data at any width');
    }

    public function testGOnANarrowBoxNeverEmptiesTheListAndResamplesAtOnce(): void
    {
        $this->resize(120, 40); // the proc box is 66 wide: no GPU column fits
        // The first collect runs before any layout (no box width: wanted), which latches measured().
        $p = ProcPanel::new(FakeProcList::demo(8))->withGpu(FakeGpuProcesses::demo());
        $first = ($p->collect(new PanelContext($this->config, $this->layout, null)))();
        $p = $this->apply($p->update($first, $this->ctx()));
        $p = $this->sample($p, 2);
        $this->assertTrue($p->gpuAvailable());
        $this->assertFalse($p->gpuUsage()->fresh(), 'narrow ticks dropped the values');

        $r = $p->update(new KeyMsg(KeyType::Char, 'g'), $this->ctx());
        $p = $this->apply($r);
        $this->assertTrue($this->config->bool('proc_gpu_only'));
        $this->assertCount(13, $p->rows($this->config), 'no judging on dropped values');
        $this->assertNotNull($r->cmd, 'a resample Cmd fetches them now');
        $msg = ($r->cmd)();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertInstanceOf(ProcGpuSample::class, $msg->snapshot);
        $p = $this->apply($p->update($msg, $this->ctx()));
        $this->assertEqualsCanonicalizing([5133, 6969, 4410, 880], array_keys($this->byPid($p)));

        // Off again: no resample needed.
        $r = $p->update(new KeyMsg(KeyType::Char, 'g'), $this->ctx());
        $this->apply($r);
        $this->assertNull($r->cmd);
    }

    public function testCyclingIntoAGpuSortResamplesWhenTheValuesWereDropped(): void
    {
        $this->resize(120, 40, ['proc_sorting' => 'io total']);
        $p = $this->sample(ProcPanel::new(FakeProcList::demo(8))->withGpu(FakeGpuProcesses::demo()), 3);
        $order = array_keys($this->byPid($p));
        $r = $p->update(new KeyMsg(KeyType::Right), $this->ctx());
        $p = $this->apply($r);
        $this->assertSame('gpu', $this->config->procSorting());
        $this->assertNotNull($r->cmd);
        $this->assertSame($order, array_keys($this->byPid($p)), 'zeros sort stably: the previous order stays');
        $p = $this->apply($p->update(($r->cmd)(), $this->ctx()));
        $this->assertSame(5133, $p->rows($this->config)[0]->pid(), 'busiest GPU process first once sampled');

        $r = $p->update(new KeyMsg(KeyType::Right), $this->ctx());
        $this->apply($r);
        $this->assertSame('gpu memory', $this->config->procSorting());
        $this->assertNull($r->cmd, 'values are fresh: nothing to fetch');
    }

    public function testAGpuCycleWithoutProcessRowsHoldsTheLastValues(): void
    {
        $measured = new GpuSnapshot([], [new GpuProcess(5133, 0, 'u', 1024, 33.0)]);
        $p = $this->sample(ProcPanel::new(FakeProcList::demo(8))->withGpu(self::scripted([$measured, new GpuSnapshot([], null), new GpuSnapshot([], [])])), 1);
        $this->assertSame(33.0, $this->byPid($p)[5133]->gpu);
        $p = $this->sample($p);
        $this->assertSame(33.0, $this->byPid($p)[5133]->gpu, 'held until the next measured snapshot');
        $this->assertSame(1024, $this->byPid($p)[5133]->gpuMem);
        $p = $this->sample($p);
        $this->assertSame(0.0, $this->byPid($p)[5133]->gpu, 'the next measured snapshot no longer lists it');
    }

    public function testAHostWithoutPerProcessDataShowsNoColumns(): void
    {
        $p = $this->demo(self::scripted([new GpuSnapshot([], null)]));
        $this->assertFalse($p->gpuAvailable());
        $this->config = $this->config->with('proc_gpu_only', true);
        $this->assertCount(13, $p->rows($this->config), 'proc_gpu_only is inert without data');
    }

    // ---- keys + button ---------------------------------------------------------------

    public function testGFlipsGpuOnlyAndFiltersTheList(): void
    {
        $p = $this->demo();
        $p = $this->key($p, new KeyMsg(KeyType::Char, 'g'));
        $this->assertTrue($this->config->bool('proc_gpu_only'));
        $this->assertEqualsCanonicalizing([5133, 6969, 4410, 880], array_keys($this->byPid($p)));
        $p = $this->key($p, new KeyMsg(KeyType::Char, 'g'));
        $this->assertFalse($this->config->bool('proc_gpu_only'));
        $this->assertCount(13, $p->rows($this->config));
    }

    public function testCtrlGFlipsItInBothModesWhileVimKeepsGForTop(): void
    {
        $p = $this->demo();
        $p = $this->key($p, new KeyMsg(KeyType::Char, 'g', ctrl: true));
        $this->assertTrue($this->config->bool('proc_gpu_only'));

        $this->config = $this->config->with('vim_keys', true)->with('proc_gpu_only', false);
        $p = $this->key($p, new KeyMsg(KeyType::Down));
        $p = $this->key($p, new KeyMsg(KeyType::Down));
        $this->assertSame(2, $p->selection()->selected);
        $p = $this->key($p, new KeyMsg(KeyType::Char, 'g'));
        $this->assertFalse($this->config->bool('proc_gpu_only'), 'vim g is home');
        $this->assertSame(1, $p->selection()->selected);
        $this->key($p, new KeyMsg(KeyType::Char, 'g', ctrl: true));
        $this->assertTrue($this->config->bool('proc_gpu_only'));
        $this->key($p, new KeyMsg(KeyType::Char, 'g', ctrl: true, alt: true));
        $this->assertTrue($this->config->bool('proc_gpu_only'), 'ctrl+alt+g is not it');
    }

    public function testTheTitleButtonTogglesEvenWithVimKeys(): void
    {
        $this->config = $this->config->with('vim_keys', true);
        $p = $this->demo();
        $buttons = ProcView::buttons($this->box->width, $this->box->height, $this->config, false, 0, null, null, true);
        $this->assertArrayHasKey('g', $buttons);
        [$bx, $by] = $buttons['g'];
        $click = new MouseMsg($this->box->x + 1 + $bx, $this->box->y + 1 + $by, MouseButton::Left, MouseAction::Press);
        $this->assertTrue($p->capturesClick($click, $this->ctx()));
        $this->apply($p->update($click, $this->ctx()));
        $this->assertTrue($this->config->bool('proc_gpu_only'));
        $this->assertArrayNotHasKey('g', ProcView::buttons($this->box->width, $this->box->height, $this->config, false, 0, null, null, false), 'no data: no button');
        $this->assertArrayNotHasKey('g', ProcView::buttons(90, 20, $this->config, false, 0, null, null, true), 'needs 82 + sort label cells');
    }

    public function testGpuMiniGraphsFollowTheDrawnRowsOnlyWhileTheirSlotShows(): void
    {
        $p = $this->demo();
        $this->assertTrue($p->gpuGraphs()->has(5133));
        $this->config = $this->config->with('proc_gpu_graphs', false);
        $q = ProcPanel::new(FakeProcList::demo(8))->withGpu(FakeGpuProcesses::demo());
        $q = $this->sample($q, 3);
        $this->assertSame(0, $q->gpuGraphs()->count());
    }

    // ---- goldens ------------------------------------------------------------------------

    private function painted(ProcPanel $p, ?Ink $ink = null): Surface
    {
        $ink ??= Ink::new(ThemeConfig::new(), ColorProfile::TrueColor);
        $s = Surface::new($this->layout->width, $this->layout->height, $ink->base());
        FrameBuilder::paintChrome($s, $this->layout, $ink, $this->config, Harness::host());
        $p->paint($s->region($this->box), new PanelFrame($this->layout, $this->box, $ink, FrameBuilder::border($this->config), $this->config, Harness::host()));

        return $s;
    }

    private function crop(Surface $s): string
    {
        $out = [];
        foreach (array_slice($s->plainLines(), $this->box->y, $this->box->height) as $line) {
            $out[] = mb_substr($line, $this->box->x, $this->box->width);
        }

        return implode("\n", $out) . "\n";
    }

    /** The box's own cells of rows [$from, $from + $count): canonical style per change, then glyphs. */
    private function cropSgr(Surface $s, int $from, int $count): string
    {
        $out = '';
        for ($y = $this->box->y + $from; $y < $this->box->y + $from + $count; $y++) {
            $active = null;
            for ($x = $this->box->x; $x < $this->box->right(); $x++) {
                $style = $s->style($x, $y);
                if ($style !== $active) {
                    $out .= "\x1b[0m" . $style;
                    $active = $style;
                }
                $out .= $s->glyph($x, $y);
            }
            $out .= "\x1b[0m\n";
        }

        return $out;
    }

    private function golden(string $name, string $actual): void
    {
        $path = self::DIR . $name;
        if (getenv('CANDY_TOP_UPDATE_GOLDENS') === '1') {
            @mkdir(self::DIR, 0777, true);
            file_put_contents($path, $actual);
            $this->markTestIncomplete('golden written: ' . $name);
        }
        $this->assertFileExists($path, 'missing golden ' . $name . ' (CANDY_TOP_UPDATE_GOLDENS=1 writes it)');
        $this->assertSame(file_get_contents($path), $actual, $name);
    }

    public function testWideGolden(): void
    {
        $p = $this->key($this->demo(), new KeyMsg(KeyType::Down));
        $this->golden('wide-180x30.txt', $this->crop($this->painted($p)));
    }

    public function testGpuSortTreeGolden(): void
    {
        $this->resize(100, 20, ['shown_boxes' => 'proc', 'proc_tree' => true, 'proc_sorting' => 'gpu']);
        $this->golden('tree-gpu-sort-100x20.txt', $this->crop($this->painted($this->demo())));
    }

    public function testGpuOnlyWithoutGpuGraphsGolden(): void
    {
        $this->resize(100, 12, ['shown_boxes' => 'proc', 'proc_gpu_graphs' => false, 'proc_sorting' => 'gpu memory']);
        $p = $this->key($this->demo(), new KeyMsg(KeyType::Char, 'g'));
        $this->golden('gpu-only-100x12.txt', $this->crop($this->painted($p)));
    }

    public function testSelectedRowSgrGolden(): void
    {
        // The title row (gpu-only button), the header, row 1 selected
        // (highlight) and six faded / metric-coloured rows down to python3's
        // busy GPU graph.
        $p = $this->key($this->demo(), new KeyMsg(KeyType::Down));
        $this->golden('wide-180x30.sgr', $this->cropSgr($this->painted($p), 0, 9));
    }

    public function testColumnsDoNotMoveTheRestOfTheRow(): void
    {
        // Everything left of the GPU tail is the no-GPU layout minus the 17 cells,
        // taken out of the command column: Cpu% keeps its distance from the left.
        $plain = $this->crop($this->painted($this->sample(ProcPanel::new(FakeProcList::demo(8)), 3)));
        $gpu = $this->crop($this->painted($this->demo()));
        $headerPlain = explode("\n", $plain)[1];
        $headerGpu = explode("\n", $gpu)[1];
        $this->assertSame(mb_strpos($headerPlain, 'Threads:') - 17, mb_strpos($headerGpu, 'Threads:'));
        $this->assertSame(mb_strpos($headerPlain, 'Cpu%') - 17, mb_strpos($headerGpu, 'Cpu%'));
        $this->assertSame(mb_strlen($headerPlain), mb_strlen($headerGpu));
        $this->assertStringEndsWith('Cpu%  GMem       Gpu%  │', $headerGpu);
    }

    public function testNoDataPaintsExactlyTheOldBox(): void
    {
        $plain = $this->painted($this->sample(ProcPanel::new(FakeProcList::demo(8)), 3));
        $none = $this->painted($this->demo(self::scripted([new GpuSnapshot([], null)])));
        $this->assertSame($this->crop($plain), $this->crop($none));
        $this->assertSame($this->cropSgr($plain, 0, 6), $this->cropSgr($none, 0, 6));
    }
}
