<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Toast\{Action, Alert, Overflow, Position, Toast, ToastType};

/**
 * Stacking truth + recovery-account pins.
 *
 * The deep-stack legs are POLICY pins: they pin the CURRENTLY DOCUMENTED
 * behaviour (README "Stacking & the frame") so any future change to growth,
 * clamping, or occlusion is a disclosed decision instead of a silent drift.
 * The a11y P5 leg pins the index fix: cancelAlert()/extendAlert() address
 * activeAlerts() order, never the invisible raw queue.
 */
final class ToastStackingRecoveryTest extends TestCase
{
    private static function strip(string $frame): string
    {
        return Ansi::strip($frame);
    }

    public function testTenPersistentAlertsGrowTheFramePastTheViewport(): void
    {
        // M7 truth: contentHeight = max(background, viewport, stack) — ten
        // 3-row boxes on a 24-row viewport emit a 30-row frame. Hosts must
        // treat frame height as a function of the stack, not of the viewport
        // (dirty-row hazard for delta renderers; stated in the README).
        $toast = Toast::new(50)->withPosition(Position::TopLeft);
        for ($i = 0; $i < 10; $i++) {
            $toast = $toast->info("alert $i");
        }

        $rows = \explode("\n", self::strip($toast->view('', 80, 24)));
        $this->assertCount(30, $rows);
        $this->assertGreaterThan(24, \count($rows));
        $this->assertSame(10, \substr_count(\implode("\n", $rows), '╭'), 'every box is emitted');
    }

    public function testDeepBottomStackPinsDocumentedGeometryPolicy(): void
    {
        // BOTTOM families clamp against the INFLATED content height, so the
        // whole stack lands inside the emitted frame (not inside the viewport
        // — that is the documented "clamp means in-frame" reading of M8).
        $toast = Toast::new(50)->withPosition(Position::BottomRight);
        for ($i = 0; $i < 4; $i++) {
            $toast = $toast->info("late $i");
        }
        $rows = \explode("\n", self::strip($toast->view('', 80, 24)));
        $tops = [];
        foreach ($rows as $index => $row) {
            if (\str_contains($row, "╭")) {
                $tops[] = $index;
            }
        }
        $this->assertCount(4, $tops);
        // The FIRST-pushed (oldest) box owns the bottom edge; siblings pile
        // UPWARD (Position's documented contract).
        $this->assertSame(21, $tops[3], 'oldest box tops the last frame row');
        $this->assertSame([12, 15, 18, 21], $tops, 'documented stack geometry for 4x3-row boxes on 24 rows');
    }

    public function testMiddlePileUpOcclusionMatchesTheDocumentedPolicy(): void
    {
        // M8: middle families pile up from their clamped first-box position;
        // siblings rendered earlier are OVERWRITTEN. Observed on this tree at
        // the flip date: six 5-row two-action boxes -> 3x '[Open]', 4x
        // '[Retry]' of a possible 6+6. Policy pin (dated 2026-11-03): if a
        // future fix adds scroll/anchor/+N-badge behaviour these counts move
        // together with the README section that documents them.
        $toast = Toast::new(40)->withPosition(Position::MiddleLeft);
        foreach (range(1, 6) as $i) {
            $toast = $toast->push(new Alert(
                ToastType::Info,
                "message $i",
                null,
                null,
                [Action::new('Open', static function (): void {}), Action::new('Retry', static function (): void {})],
            ));
        }
        $frame = self::strip($toast->view('', 80, 24));
        $this->assertSame(3, \substr_count($frame, '[Open]'));
        $this->assertSame(4, \substr_count($frame, '[Retry]'));
    }

    public function testDropOldestEvicteeRidesTheHistoryLog(): void
    {
        // M9: the victim of an overflow eviction used to vanish with zero
        // trace (history() only ever recorded dismiss()).
        $toast = Toast::new(50)
            ->withMaxConcurrent(1)
            ->withOverflow(Overflow::DropOldest)
            ->info('first')
            ->info('second');

        $this->assertSame(['second'], \array_map(
            static fn(Alert $a): string => (string) $a->message,
            $toast->activeAlerts()
        ));
        $this->assertSame(['first'], \array_map(
            static fn(Alert $a): string => (string) $a->message,
            $toast->history()
        ));
    }

    public function testDropNewestRefusalStaysUnrecordedAndDocumented(): void
    {
        // The refused incoming was never displayed — filing it in the history
        // would LIE about it having been seen. Honest shape today: silent
        // refusal, disclosed in README + appendBounded docblock; a discard
        // signal is a recorded seam row (needs a cross-API return-shape).
        $toast = Toast::new(50)
            ->withMaxConcurrent(1)
            ->withOverflow(Overflow::DropNewest)
            ->info('kept')
            ->error('refused');

        $this->assertSame(['kept'], \array_map(
            static fn(Alert $a): string => (string) $a->message,
            $toast->activeAlerts()
        ));
        $this->assertSame([], $toast->history());
    }

    public function testClearIsBulkResetNotDismissal(): void
    {
        $toast = Toast::new(50)->info('gone')->warning('also gone');
        $cleared = $toast->clear();

        $this->assertSame([], $cleared->activeAlerts());
        $this->assertSame([], $cleared->history(), 'clear() files nothing — use dismiss() for the audit trail');
    }

    public function testQueueMutatorsAddressActiveAlertsOrder(): void
    {
        // a11y P5 / m1: with an expired entry parked at raw slot 0 (expired
        // alerts only leave the queue on the next write), cancelAlert(0) used
        // to hit the INVISIBLE alert while the visible toast kept its timer.
        $toast = Toast::new(50)
            ->push(new Alert(ToastType::Info, 'stale-invisible', \microtime(true) - 10))
            ->push(new Alert(ToastType::Error, 'live-visible', \microtime(true) + 60));

        $cancelled = $toast->cancelAlert(0);

        $active = $cancelled->activeAlerts();
        $this->assertCount(1, $active);
        $this->assertSame('live-visible', $active[0]->message);
        $this->assertTrue($active[0]->persistent, 'the index addressed the VISIBLE alert');

        $extended = $toast->extendAlert(0, 3600);
        $live = $extended->activeAlerts()[0];
        $this->assertGreaterThan(
            \microtime(true) + 3500,
            (float) $live->expiresAt,
            'extendAlert also maps through the active order'
        );
    }

    public function testIndexPastTheEndOfActiveAlertsIsANoOp(): void
    {
        $toast = Toast::new(50)->push(new Alert(ToastType::Info, 'only', \microtime(true) + 60));
        $this->assertSame($toast, $toast->cancelAlert(1), 'out-of-range is a no-op, unchanged instance');
        $this->assertFalse($toast->cancelAlert(1)->activeAlerts()[0]->persistent);
    }
}
