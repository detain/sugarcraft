<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Alert, HistoryLog, Toast, ToastType};
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle pins for the dismiss/clear repair (crush_libs.md sugar-toast #1/#2):
 * dismiss() stops rendering and MOVES live alerts to history, clear() is the
 * documented revival (queue AND flag), writes refuse a dismissed instance, and
 * expired alerts leave the queue on the next write so it stays bounded.
 */
final class ToastDismissLifecycleTest extends TestCase
{
    // ------------------------------------------------------------------
    // FIX #1 — dismiss must not permanently brick the instance
    // ------------------------------------------------------------------

    /**
     * The documented PROOF GAP: dismiss -> clear -> alert -> view renders
     * again. Before the repair, clear() left the dismissed flag set, so every
     * later frame was the bare background.
     */
    public function testDismissThenClearThenAlertRendersAgain(): void
    {
        $bg = \str_repeat("background line\n", 10);

        $t = Toast::new(50)
            ->success('First')
            ->dismiss()
            ->clear()
            ->alert(ToastType::Info, 'Back');

        $this->assertFalse($this->dismissedFlag($t), 'clear() must reset the dismissed flag');
        $frame = $t->view($bg, 80, 10);
        $this->assertStringContainsString('Back', $frame);
        $this->assertStringContainsString('background', $frame);
    }

    public function testAlertAfterDismissThrowsInsteadOfQueueingIntoDeadInstance(): void
    {
        $t = Toast::new(50)->success('x')->dismiss();

        try {
            $t->alert(ToastType::Info, 'ignored');
            $this->fail('alert() on a dismissed Toast must throw LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('clear()', $e->getMessage());
        }
    }

    public function testProgressToastAndPushAlsoRefuseADismissedInstance(): void
    {
        $t = Toast::new(50)->info('x')->dismiss();

        $this->expectException(\LogicException::class);
        $t->progressToast(ToastType::Info, 'progress', 0.5);
    }

    public function testPushRefuseADismissedInstance(): void
    {
        $t = Toast::new(50)->info('x')->dismiss();

        $this->expectException(\LogicException::class);
        $t->push(new Alert(ToastType::Info, 'pre-built'));
    }

    // ------------------------------------------------------------------
    // FIX #2b — dismiss MOVES to history (no copy, no double count)
    // ------------------------------------------------------------------

    public function testDismissMovesLiveAlertsOutOfTheQueue(): void
    {
        $t = Toast::new(50)->success('a')->error('b')->dismiss();

        $this->assertSame([], $this->queueOf($t), 'dismiss is a move, not a copy');
        $this->assertCount(2, $t->history());
    }

    public function testRepeatedDismissCannotDoubleCountHistory(): void
    {
        $t = Toast::new(50)->success('only');

        $first = $t->dismiss();
        $second = $first->dismiss();

        $this->assertCount(1, $first->history());
        $this->assertSame($first, $second, 'dismiss on an already-dismissed Toast is idempotent');
    }

    // ------------------------------------------------------------------
    // FIX #2a — expired alerts leave the queue on the next write
    // ------------------------------------------------------------------

    public function testExpiredAlertLeavesQueueOnNextWrite(): void
    {
        $t = Toast::new(50)
            ->alert(ToastType::Info, 'gone', \microtime(true) - 1)
            ->success('fresh');

        $queue = $this->queueOf($t);
        $this->assertCount(1, $queue);
        $this->assertSame('fresh', $queue[0]->message);
    }

    /**
     * The staleness/boundedness pin: a null-cap Toast kept alive across many
     * expire+append rounds no longer leaks queue entries — each write prunes
     * the previous round's expired cruft, so the size stays at most 1 here
     * (only the just-appended, itself-expired alert survives until the next
     * write) instead of growing with every round.
     */
    public function testQueueStaysBoundedAcrossExpiryAppendRounds(): void
    {
        $t = Toast::new(50);  // maxConcurrent null = unlimited
        for ($i = 0; $i < 50; $i++) {
            $t = $t->alert(ToastType::Info, "expired-$i", \microtime(true) - 1);
        }

        $this->assertLessThanOrEqual(
            1,
            \count($this->queueOf($t)),
            'expired alerts must not accumulate in the queue across writes'
        );
    }

    public function testMixedLiveAndExpiredQueuePrunesOnlyExpiredOnWrite(): void
    {
        $base = \microtime(true);
        $t = Toast::new(50)
            ->alert(ToastType::Info, 'live', $base + 60)
            ->alert(ToastType::Error, 'dead', $base - 1)
            ->success('trigger');   // this write prunes 'dead', keeps 'live'

        $messages = \array_map(fn(Alert $a): string => (string) $a->message, $this->queueOf($t));
        $this->assertSame(['live', 'trigger'], $messages);
    }

    // ------------------------------------------------------------------
    // FIX #2c — HistoryLog cap (withHistoryLimit, default 100)
    // ------------------------------------------------------------------

    public function testHistoryCapEvictsOldestFirst(): void
    {
        $t = Toast::new(50)->withHistoryLimit(2);

        foreach (['m1', 'm2', 'm3'] as $m) {
            $t = $t->success($m)->dismiss()->clear();
        }

        $history = $t->history();
        $this->assertCount(2, $history);
        $this->assertSame('m2', $history[0]->message);
        $this->assertSame('m3', $history[1]->message);
    }

    public function testDefaultHistoryLimitIsOneHundred(): void
    {
        $t = Toast::new(50);
        for ($i = 1; $i <= 105; $i++) {
            $t = $t->success("m{$i}")->dismiss()->clear();
        }

        $history = $t->history();
        $this->assertCount(100, $history);
        $this->assertSame('m6', $history[0]->message, 'oldest five evicted');
    }

    public function testNullHistoryLimitIsExplicitlyUnbounded(): void
    {
        $t = Toast::new(50)->withHistoryLimit(null);
        for ($i = 1; $i <= 105; $i++) {
            $t = $t->success("m{$i}")->dismiss()->clear();
        }

        $this->assertCount(105, $t->history());
    }

    public function testNegativeHistoryLimitFailsFast(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Toast::new(50)->withHistoryLimit(-1);
    }

    public function testHistoryLogDirectPushKeepsUnboundedDefault(): void
    {
        // push() without a limit stays exactly the old append — the cap is a
        // Toast-level policy, not a HistoryLog default.
        $log = (new HistoryLog())
            ->push(new Alert(ToastType::Info, 'a'), 1)
            ->push(new Alert(ToastType::Info, 'b'));

        $this->assertCount(2, $log->all());
        $this->assertSame('b', $log->all()[1]->message);
    }

    // ------------------------------------------------------------------
    // Reflection helpers (unique names: no cross-file helper duplication)
    // ------------------------------------------------------------------

    /** @return list<Alert> */
    private function queueOf(Toast $t): array
    {
        $prop = (new \ReflectionClass($t))->getProperty('queue');
        $prop->setAccessible(true);
        return $prop->getValue($t);
    }

    private function dismissedFlag(Toast $t): bool
    {
        $prop = (new \ReflectionClass($t))->getProperty('dismissed');
        $prop->setAccessible(true);
        return $prop->getValue($t);
    }
}
