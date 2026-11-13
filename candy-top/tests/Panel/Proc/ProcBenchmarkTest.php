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
}
