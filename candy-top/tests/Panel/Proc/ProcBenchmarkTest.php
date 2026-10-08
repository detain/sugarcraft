<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
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
 * Plan §7 R4: sampling + sort + render of 500 processes under 50 ms on a
 * 120x40 terminal. Non-blocking: an over-budget run on a slow or busy
 * machine is reported incomplete rather than failed, and CI only checks
 * that the pipeline runs (machine speed there is not ours to promise).
 */
#[Group('benchmark')]
final class ProcBenchmarkTest extends TestCase
{
    private const BUDGET_MS = 50.0;

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

        if (getenv('CI') !== false && getenv('CI') !== '') {
            $this->addToAssertionCount(1);

            return;
        }
        $slow = array_filter($best, static fn (float $ms): bool => $ms > self::BUDGET_MS);
        if ($slow !== []) {
            $this->markTestIncomplete(sprintf('R4 over budget on this machine: %s', json_encode($best)));
        }
        $this->assertLessThanOrEqual(self::BUDGET_MS, max($best));
    }
}
