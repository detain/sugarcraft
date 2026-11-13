<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Process;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\PanelContext;
use SugarCraft\Top\Panel\PanelFrame;
use SugarCraft\Top\Panel\ProcPanel;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Tests\Support\Harness;
use SugarCraft\Top\Theme\ThemeConfig;
use SugarCraft\Top\View\FrameBuilder;
use SugarCraft\Top\View\Ink;
use SugarCraft\Top\View\Surface;

/**
 * Budget: sampling + sort + render of 500 processes under 50 ms on a
 * 120x40 terminal. The budget is local-owned and soft: an over-budget run
 * on a slow or busy machine is reported incomplete with the numbers, never
 * failed (S-N09: the old CI leg self-skipped before measuring, so no
 * machine ever reported a figure). CI enforces a 10x wall — a real
 * complexity regression breaches it; machine noise does not — and both
 * messages carry the best-of-5 milliseconds so there is always a number.
 */
#[Group('benchmark')]
final class ProcBenchmarkTest extends TestCase
{
    private const BUDGET_MS = 50.0;
    private const CEILING_MS = 500.0;

    public function testFiveHundredProcessesFitTheFrameBudget(): void
    {
        $best = [];
        foreach ([false, true] as $tree) {
            $config = Config::new()->with('proc_tree', $tree);
            $layout = FrameBuilder::layout(120, 40, $config, 8);
            $box = $layout->box('proc');
            $this->assertNotNull($box);
            $ctx = new PanelContext($config, $layout, $box);
            $frame = new PanelFrame($layout, $box, Ink::new(ThemeConfig::new()), FrameBuilder::border($config), $config, Harness::host());
            $panel = ProcPanel::new(FakeProcList::demo(8, 487));
            $runs = [];
            for ($i = 0; $i < 5; $i++) {
                $t = hrtime(true);
                $msg = ($panel->collect($ctx))();
                $panel = $panel->update($msg, $ctx)->panel;
                $panel->paint(Surface::new(120, 40)->region($box), $frame);
                $runs[] = (hrtime(true) - $t) / 1e6;
            }
            $this->assertInstanceOf(ProcPanel::class, $panel);
            $this->assertCount(500, $panel->snapshot()?->processes ?? []);
            $best[$tree ? 'tree' : 'flat'] = min($runs);
        }

        $worst = max($best);
        $figures = json_encode($best);
        if ($worst > self::CEILING_MS) {
            $this->fail(sprintf('proc scan blew the %.0f ms wall: %s', self::CEILING_MS, $figures));
        }
        if ($worst > self::BUDGET_MS) {
            $this->markTestIncomplete(sprintf('proc scan over budget on this machine: %s', $figures));
        }
        $this->assertLessThanOrEqual(self::BUDGET_MS, $worst);
    }


    /**
     * candy-top-102 / S-N07: an unchanged-majority tick must carry its
     * ProcEntry objects instead of rebuilding all n. FakeProcList waves
     * every row's cpu each tick, so the leg drives a stable source: one
     * row moves, n-1 ride the carry. The deterministic proof is object
     * identity (reflection); the timing leg keeps the 4k figure on the
     * record against the wall.
     */
    public function testUnchangedMajorityRidesTheCarryAtFourThousand(): void
    {
        $config = Config::new();
        $layout = FrameBuilder::layout(120, 40, $config, 8);
        $box = $layout->box('proc');
        $this->assertNotNull($box);
        $ctx = new PanelContext($config, $layout, $box);
        $frame = new PanelFrame($layout, $box, Ink::new(ThemeConfig::new()), FrameBuilder::border($config), $config, Harness::host());

        $rows = [];
        for ($k = 0; $k < 4000; $k++) {
            $rows[] = new Process(10_000 + $k, 1, 'idle-' . $k, '/usr/sbin/idle-' . $k, 'root', 0, 'S', 1, 0, 4 * 1024 * 1024, 0.0, 0.0);
        }
        $panel = ProcPanel::new(new StableProcSource($rows));

        $entries = new \ReflectionProperty(ProcPanel::class, 'entries');
        $collect = static function (ProcPanel $p) use ($ctx, $entries): array {
            $msg = ($p->collect($ctx))();
            $next = $p->update($msg, $ctx)->panel;

            return [$next, $entries->getValue($next)];
        };

        $best = PHP_INT_MAX;
        for ($i = 0; $i < 5; $i++) {
            $t = hrtime(true);
            [$panel, $tickEntries] = $collect($panel);
            $panel->paint(Surface::new(120, 40)->region($box), $frame);
            $best = min($best, (hrtime(true) - $t) / 1e6);
        }
        $this->assertCount(4000, $tickEntries);
        $before = [];
        foreach ($tickEntries as $e) {
            $before[spl_object_id($e)] = true;
        }

        [$panel, $after] = $collect($panel); // one row moves: the rest is the unchanged majority
        $carried = 0;
        $moved = null;
        foreach ($after as $e) {
            if (isset($before[spl_object_id($e)])) {
                $carried++;
            } else {
                $moved = $e->pid();
            }
        }
        $this->assertSame(3999, $carried, 'every unchanged row rode its previous object');
        $this->assertSame(10_000, $moved, 'the moved row is the only rebuilt entry');

        $figures = sprintf('4000 procs, one changed per tick, best %.2f ms', $best);
        if ($best > 1000.0) {
            $this->fail('4k unchanged-majority tick blew the 1000 ms wall: ' . $figures);
        }
        fwrite(STDOUT, "\n[proc-benchmark] {$figures}\n");
    }
}

/**
 * A Source whose rows stay put except a one-row cpu wobble: the
 * idle-daemon-majority host shape the S-N07 carry is for (FakeProcList
 * waves every cpu each tick, which would rebuild every row honestly and
 * hide the fold).
 */
final class StableProcSource implements \SugarCraft\Top\Source\Source
{
    /** @param list<Process> $rows */
    public function __construct(
        private readonly array $rows,
        private readonly int $cores = 8,
    ) {
    }

    /** @return array{0: \SugarCraft\Top\Collect\ProcSnapshot, 1: self} */
    public function sample(): array
    {
        $rows = $this->rows;
        $first = $rows[0];
        $rows[0] = new Process(
            $first->pid,
            $first->ppid,
            $first->name,
            $first->cmd,
            $first->user,
            $first->uid,
            $first->state,
            $first->threads,
            $first->nice,
            $first->mem,
            $first->cpu + 0.5,
            $first->cpuCumulative + 1.0,
        );

        return [new \SugarCraft\Top\Collect\ProcSnapshot($rows, $this->cores), new self($rows, $this->cores)];
    }
}
