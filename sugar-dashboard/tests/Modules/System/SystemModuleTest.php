<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Modules\System;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Msg;
use SugarCraft\Dashboard\Module\ProcAvailability;
use SugarCraft\Dashboard\Modules\System\RefreshMsg;
use SugarCraft\Dashboard\Modules\System\SystemModule;

/**
 * Tests for SystemModule.
 */
final class SystemModuleTest extends TestCase
{
    protected function setUp(): void
    {
        // Cold-ambient door state: COMP-2 memo injection in one test must
        // never leak into another test's readable-branch assertion.
        ProcAvailability::resetForTesting();
    }

    protected function tearDown(): void
    {
        ProcAvailability::resetForTesting();
    }

    public function testNameReturnsSystem(): void
    {
        $module = new SystemModule();
        $this->assertSame('system', $module->name());
    }

    public function testMinSizeReturnsExpectedDimensions(): void
    {
        $module = new SystemModule();
        $this->assertSame([30, 5], $module->minSize());
    }

    public function testInitReturnsTickClosure(): void
    {
        $module = new SystemModule();
        $initResult = $module->init();

        $this->assertNotNull($initResult);
        $this->assertInstanceOf(\Closure::class, $initResult);
    }

    public function testInitTickClosureReturnsMsg(): void
    {
        $module = new SystemModule();
        $initResult = $module->init();

        $this->assertNotNull($initResult);
        $msg = $initResult();

        // The tick closure should return a Msg
        $this->assertInstanceOf(Msg::class, $msg);
    }

    public function testUpdateWithRefreshMsgReturnsNewModuleAndTick(): void
    {
        $module = new SystemModule();
        $msg = new RefreshMsg();

        [$nextModule, $cmd] = $module->update($msg);

        $this->assertNotSame($module, $nextModule);
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testUpdateWithUnrelatedMsgNeverResamples(): void
    {
        $module = new SystemModule();
        $msg = new class implements Msg {};

        [$nextModule, $cmd] = $module->update($msg);

        // Sampling is RefreshMsg-only: an unrelated message returns the very
        // same instance with zero /proc reads, zero child spawns.
        $this->assertSame($module, $nextModule);
        $this->assertNull($cmd);
    }

    public function testColdViewCarriesNoFabricatedBars(): void
    {
        // Before any sample the rows must read 'n/a' (and 'unknown' uptime) —
        // a pre-first-tick 0% full-width bar was a fabricated measurement.
        $view = (new SystemModule())->view();

        $this->assertStringContainsString('CPU n/a', $view);
        $this->assertStringContainsString('MEM n/a', $view);
        $this->assertStringNotContainsString('░', $view);
        $this->assertStringNotContainsString('█', $view);
    }

    public function testCpuNeedsTwoSamplesAndRemembersBaselinePerInstance(): void
    {
        if (!is_readable('/proc/stat')) {
            $this->markTestSkipped('two-sample differential pin needs a /proc host (Linux)');
        }
        ProcAvailability::resetForTesting();

        $module = new SystemModule();
        [$first] = $module->update(new RefreshMsg());
        [$second] = $first->update(new RefreshMsg());

        // First sample only seeds the differential baseline — honest -1.0,
        // not a fake 0.0. The second computes a real percentage.
        $this->assertSame(ProcAvailability::UNMEASURED, $first->getState()['cpuLoad']);
        $this->assertGreaterThanOrEqual(0.0, $second->getState()['cpuLoad']);

        // A fresh instance must NOT inherit the first instance's baseline:
        // process-global statics used to leak across modules (and tests).
        $fresh = new SystemModule();
        [$freshFirst] = $fresh->update(new RefreshMsg());
        $this->assertSame(ProcAvailability::UNMEASURED, $freshFirst->getState()['cpuLoad']);
    }

    public function testViewReturnsNonEmptyString(): void
    {
        $module = new SystemModule();
        $view = $module->view();

        $this->assertIsString($view);
        $this->assertNotSame('', $view);
    }

    public function testViewContainsExpectedLabels(): void
    {
        $module = new SystemModule();
        $view = $module->view();

        // System module view should contain CPU, MEM labels
        $this->assertStringContainsString('CPU', $view);
        $this->assertStringContainsString('MEM', $view);
    }

    public function testViewContainsUptime(): void
    {
        $module = new SystemModule();
        $view = $module->view();

        // Should contain UPTIME label
        $this->assertStringContainsString('UPTIME', $view);
    }

    public function testUpdateProducesModuleWithState(): void
    {
        $module = new SystemModule();
        $msg = new RefreshMsg();

        [$nextModule] = $module->update($msg);

        // The updated module should have some state
        $state = $nextModule->getState();
        $this->assertIsArray($state);
    }

    public function testMultipleUpdatesWork(): void
    {
        $module = new SystemModule();
        $msg = new RefreshMsg();

        [$next1] = $module->update($msg);
        [$next2] = $next1->update($msg);

        // Each update should produce a valid module
        $this->assertIsString($next1->view());
        $this->assertIsString($next2->view());
    }

    public function testViewUpdatesAfterRefreshMsg(): void
    {
        $module = new SystemModule();
        $msg = new RefreshMsg();

        // Initial view
        $view1 = $module->view();

        // View after refresh
        [$nextModule] = $module->update($msg);
        $view2 = $nextModule->view();

        // Both should be valid strings
        $this->assertIsString($view1);
        $this->assertIsString($view2);
    }

    // ---- E731 COMP-2 option (b): probe-once, degrade quietly, render 'n/a' ----

    public function testViewRendersVisibleSentinelRowsWhenProcAbsent(): void
    {
        // The readers' /proc paths are hardcoded, so is_readable() is unfakeable
        // from a test (COMP-2 design note) — the availability memo is injected.
        ProcAvailability::markForTesting('/proc/stat', false);
        ProcAvailability::markForTesting('/proc/meminfo', false);
        ProcAvailability::markForTesting('/proc/uptime', false);

        $module = new SystemModule();
        [$next] = $module->update(new RefreshMsg());
        $rows = explode("\n", $next->view());

        // CPU/MEM are positional (GPU, if present, is appended after them);
        // the sentinel replaces the fake "0% [full idle bar]" row entirely.
        $this->assertSame('CPU n/a', $rows[0]);
        $this->assertSame('MEM n/a', $rows[1]);
        $this->assertSame('UPTIME n/a', $rows[count($rows) - 1]);
        $this->assertStringNotContainsString('░', $rows[0] . $rows[1]);
    }

    public function testStateCarriesUnmeasuredSentinelAndHistoryRefusesAccumulation(): void
    {
        ProcAvailability::markForTesting('/proc/stat', false);
        ProcAvailability::markForTesting('/proc/meminfo', false);
        ProcAvailability::markForTesting('/proc/uptime', false);

        $module = new SystemModule();
        [$first] = $module->update(new RefreshMsg());
        [$second] = $first->update(new RefreshMsg());
        $state = $second->getState();

        // Percentages carry the negative sentinel (GPU's in-class precedent)…
        $this->assertSame(-1.0, $state['cpuLoad']);
        $this->assertSame(-1.0, $state['memLoad']);
        // …uptime, being a string, carries the visible sentinel itself…
        $this->assertSame('n/a', $state['uptime']);
        // …and history does not accumulate while unmeasured (two ticks in,
        // still no samples — the fake-0 trend pollution is what this pins).
        $this->assertSame([], $state['cpuHistory']);
        $this->assertSame([], $state['memHistory']);
    }

    public function testRenderLoadRowDecisionIsPureOnBothPolarities(): void
    {
        // Platform-neutral pin of the pure render decision: measured values
        // reproduce the historical bytes exactly (probe-once degraded renders
        // proved by the memo-injected integration pins above).
        $render = new \ReflectionMethod(SystemModule::class, 'renderLoadRow');
        $render->setAccessible(true);
        $module = new SystemModule();

        $this->assertSame(
            'CPU n/a',
            $render->invoke($module, 'CPU', ProcAvailability::UNMEASURED)
        );
        $this->assertSame(
            'CPU   0% ' . str_repeat('░', 70),
            $render->invoke($module, 'CPU', 0.0)
        );
        $this->assertSame(
            'CPU  50% ' . str_repeat('█', 35) . str_repeat('░', 35),
            $render->invoke($module, 'CPU', 50.0)
        );
        $this->assertSame(
            'MEM 100% ' . str_repeat('█', 70),
            $render->invoke($module, 'MEM', 100.0)
        );
    }

    public function testReadablePolarityRendersRealRowsOnProcHosts(): void
    {
        if (!is_readable('/proc/stat')) {
            $this->markTestSkipped('readable-branch integration pin needs a /proc host (Linux)');
        }
        // Cold ambient probe: every door answers true on Linux → the real
        // render bytes are exercised on CI (the sentinel path stays dormant).
        ProcAvailability::resetForTesting();

        $module = new SystemModule();
        [$first] = $module->update(new RefreshMsg());
        [$second] = $first->update(new RefreshMsg());
        [$third] = $second->update(new RefreshMsg());
        $view = $third->view();

        $this->assertMatchesRegularExpression('/^CPU +\d+% [█░]{70}$/mu', $view);
        $this->assertMatchesRegularExpression('/^MEM +\d+% [█░]{70}$/mu', $view);
        $this->assertStringNotContainsString('n/a', $view);

        $state = $third->getState();
        $this->assertGreaterThanOrEqual(0.0, $state['cpuLoad']);
        $this->assertGreaterThanOrEqual(0.0, $state['memLoad']);
        // The CPU series only counts samples after the baseline seed, so a
        // readable host accumulates two entries from three ticks; the memory
        // series measures from the first tick (no differential needed).
        $this->assertCount(2, $state['cpuHistory']);
        $this->assertCount(3, $state['memHistory']);
    }
}
