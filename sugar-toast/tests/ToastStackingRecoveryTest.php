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

    public function testTenPersistentAlertsFoldToTheViewportBudget(): void
    {
        // TST-LAYOUT fold-to-fit (disclosed re-pin of the old growth pin: ten
        // 3-row boxes used to emit a 30-row frame for a 24-row viewport).
        // The frame now stays at the viewport height: seven whole boxes fit
        // beside the one-row fold, the other three ride the '+3 hidden' row
        // and stay live in activeAlerts().
        $toast = Toast::new(50)->withPosition(Position::TopLeft);
        for ($i = 0; $i < 10; $i++) {
            $toast = $toast->info("alert $i");
        }

        $rows = \explode("\n", self::strip($toast->view('', 80, 24)));
        $this->assertCount(24, $rows);
        $text = \implode("\n", $rows);
        $this->assertSame(7, \substr_count($text, '╭'), 'only the boxes that fit are painted');
        $this->assertStringContainsString('+3 hidden', $text);
        $this->assertCount(10, $toast->activeAlerts(), 'hidden alerts stay live');
        $this->assertStringContainsString('alert 9', $text, 'DropOldest policy keeps the newest');
        $this->assertStringNotContainsString('alert 0', $text);
    }

    public function testDropNewestPolicyKeepsTheOldestWhenFolding(): void
    {
        $toast = Toast::new(50)->withPosition(Position::TopLeft)->withOverflow(Overflow::DropNewest);
        for ($i = 0; $i < 10; $i++) {
            $toast = $toast->info("alert $i");
        }
        $text = self::strip($toast->view('', 80, 24));
        $this->assertStringContainsString('alert 0', $text);
        $this->assertStringNotContainsString('alert 9', $text);
        $this->assertStringContainsString('+3 hidden', $text);
    }

    public function testAFittingStackHasNoFoldRow(): void
    {
        $toast = Toast::new(50)->withPosition(Position::TopLeft)->info('a')->info('b');
        $this->assertStringNotContainsString('hidden', self::strip($toast->view('', 80, 24)));
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

    public function testMiddleStackFoldsInsteadOfOccludingItself(): void
    {
        // TST-LAYOUT (disclosed re-pin of the M8 'middle pile-up occlusion'
        // policy): six 5-row two-action boxes used to clamp to row 0 and
        // paint over each other (3x '[Open]', 4x '[Retry]' of a possible
        // 6+6, two boxes buried). The middle stack now keeps only the boxes
        // that fit WITHOUT overlap — the two newest — and folds the other
        // four into '+4 hidden'; every painted button is visible.
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
        $this->assertSame(2, \substr_count($frame, '[Open]'));
        $this->assertSame(2, \substr_count($frame, '[Retry]'));
        $this->assertStringContainsString('+4 hidden', $frame);
        foreach ([5, 6] as $i) {
            $this->assertSame(1, \substr_count($frame, "message {$i}"), "message {$i} painted exactly once");
        }
        $this->assertSame(6, \count($toast->activeAlerts()));
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

    public function testDropNewestRefusalFilesTheArrivalInHistory(): void
    {
        // TST-DROPINFO: the refused incoming was never displayed, and the
        // history row is its ONLY trace — a silent disappearance gave the
        // host no way to announce an over-cap arrival. Filing is symmetric
        // with the DropOldest eviction leg.
        $toast = Toast::new(50)
            ->withMaxConcurrent(1)
            ->withOverflow(Overflow::DropNewest)
            ->info('kept')
            ->error('refused');

        $this->assertSame(['kept'], \array_map(
            static fn(Alert $a): string => (string) $a->message,
            $toast->activeAlerts()
        ));
        $this->assertSame(['refused'], \array_map(
            static fn(Alert $a): string => (string) $a->message,
            $toast->history()
        ));
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

    public function testShortViewportStackYMathIsPinnedForAllNineAnchors(): void
    {
        // TST-LAYOUT fold-to-fit leg: under a 5-row viewport with six
        // persistent 3-row boxes the painted stack is capped to the canvas —
        // ONE box (the newest, per DropOldest) plus the one-row '+5 hidden'
        // fold, 5 rows for EVERY anchor (it used to grow to 18 with the
        // newest box at frame row 0 on bottom anchors). Top stacks run
        // downward, Bottom stacks hug the bottom edge, Middle centres. y
        // values are [box, fold].
        $layout = new \ReflectionMethod(Toast::class, 'layout');
        $expected = [
            'TopLeft' => [0, 3],
            'TopCenter' => [0, 3],
            'TopRight' => [0, 3],
            'BottomLeft' => [2, 1],
            'BottomCenter' => [2, 1],
            'BottomRight' => [2, 1],
            'MiddleLeft' => [1, 0],
            'MiddleCenter' => [1, 0],
            'MiddleRight' => [1, 0],
        ];

        foreach (Position::cases() as $position) {
            $toast = Toast::new(60)->withPosition($position)->withDuration(null);
            foreach (['one', 'two', 'three', 'four', 'five', 'six'] as $message) {
                $toast = $toast->alert(ToastType::Info, $message);
            }
            /** @var array{boxes: list<array{y:int,fold:int,index:int}>, contentHeight:int} $r */
            $r = $layout->invoke($toast, '', 60, 5);
            $this->assertSame(5, $r['contentHeight'], "{$position->name}: frame must not outgrow the viewport");
            $this->assertSame($expected[$position->name], \array_column($r['boxes'], 'y'), "{$position->name}: y-math moved");
            $this->assertSame([5, -1], \array_column($r['boxes'], 'index'), "{$position->name}: newest + fold");
            $this->assertSame(5, $r['boxes'][1]['fold'], "{$position->name}: hidden count");
            $this->assertLessThanOrEqual(5, \count(\explode("\n", \rtrim($toast->view('', 60, 5), "\n"))), "{$position->name}: emitted rows");
        }
    }
}
