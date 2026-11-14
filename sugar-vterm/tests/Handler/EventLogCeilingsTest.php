<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Handler;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Handler\ScreenHandler;
use SugarCraft\Vterm\Msg\FocusInMsg;
use SugarCraft\AnsiParser\Parser\DebugHandler;
use SugarCraft\Vterm\Screen\Screen;
use SugarCraft\Vterm\Terminal as RendererTerminal;
use SugarCraft\Vterm\Terminal\Terminal as FullTerminal;

/**
 * Hostile-input residency ceilings on the event/log channels (audit C3):
 * the OSC 52 clipboard log, the focus-event log, the DEC 2026 pending
 * mutation queue, and the DebugHandler accumulator are all bounded rings —
 * a stream that arms these channels itself can never grow them without
 * bound. Also pins the facade DRAIN semantics of clipboardEvents() and
 * focusEvents(): the first read returns the backlog and clears it.
 */
final class EventLogCeilingsTest extends TestCase
{
    private const EVENTS_CAP = 1024;

    /** Feed a byte stream straight into a fresh handler (no facade). */
    private function feedHandler(string $bytes, int $cols = 20, int $rows = 5): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);

        return $h;
    }

    private function queueLength(ScreenHandler $h): int
    {
        $prop = new ReflectionProperty(ScreenHandler::class, 'pendingMutations');

        return \count($prop->getValue($h));
    }

    private function syncMutationCap(): int
    {
        return (new ReflectionClass(ScreenHandler::class))->getConstant('MAX_SYNC_MUTATIONS');
    }

    public function testClipboardRingStopsAtTheEventCap(): void
    {
        $t = FullTerminal::new(20, 5);
        $flood = '';
        for ($i = 0; $i < self::EVENTS_CAP + 100; $i++) {
            $flood .= "\x1b]52;c;AAA\x07";
        }
        $t->feed($flood);

        $events = $t->clipboardEvents();
        self::assertCount(self::EVENTS_CAP, $events);
        // Drop-OLDEST: the newest write is still present.
        self::assertSame('write', $events[self::EVENTS_CAP - 1]['kind']);
        self::assertSame('AAA', $events[self::EVENTS_CAP - 1]['payload']);
    }

    public function testClipboardFacadeDrainClearsTheLog(): void
    {
        $t = FullTerminal::new(20, 5);
        $t->feed("\x1b]52;c;QUFB\x07");
        $t->feed("\x1b]52;c;PQ==\x07");

        $first = $t->clipboardEvents();
        self::assertCount(2, $first);
        self::assertSame([], $t->clipboardEvents(), 'second read must drain to empty');
    }

    public function testClipboardReadRequestsJoinTheSameRing(): void
    {
        $t = FullTerminal::new(20, 5);
        $flood = '';
        for ($i = 0; $i < self::EVENTS_CAP + 50; $i++) {
            $flood .= "\x1b]52;c;?\x07";
        }
        $t->feed($flood);

        $events = $t->clipboardEvents();
        self::assertCount(self::EVENTS_CAP, $events);
        self::assertSame('read', $events[0]['kind']);
    }

    public function testFocusRingStopsAtTheEventCap(): void
    {
        $t = FullTerminal::new(20, 5);
        $t->feed("\x1b[?1004h" . str_repeat("\x1b[I", self::EVENTS_CAP + 100));

        $events = $t->focusEvents();
        self::assertCount(self::EVENTS_CAP, $events);
        self::assertContainsOnlyInstancesOf(FocusInMsg::class, $events);
        self::assertSame([], $t->focusEvents(), 'second read must drain to empty');
    }

    public function testFocusLogStaysCappedAcrossRepeatedFills(): void
    {
        $t = FullTerminal::new(20, 5);
        $t->feed("\x1b[?1004h");
        for ($round = 0; $round < 3; $round++) {
            $t->feed(str_repeat("\x1b[I", self::EVENTS_CAP + 10));
            self::assertLessThanOrEqual(self::EVENTS_CAP, \count($t->focusEvents()));
        }
    }

    public function testSyncPrintFloodFlushesAtTheMutationCeiling(): void
    {
        $cap = $this->syncMutationCap();
        // CUP-homed single writes: the cursor jump is immediate, the 'A'
        // queues — no wrapping, no scrolling (this library scrolls live even
        // inside a sync window, and a wrapped flood's flushes scroll away).
        $units = intdiv($cap, 1) + 4000;
        $h = new ScreenHandler(new Buffer(20, 5));
        (new Parser($h))->feed("\x1b[?2026h" . str_repeat("\x1b[1;1HA", $units));

        self::assertLessThanOrEqual($cap, $this->queueLength($h));
        // The overflow was FLUSHED to the grid mid-window, not dropped:
        // (0,0) already carries the replayed 'A' before the window closes.
        self::assertSame('A', $h->buffer->cell(0, 0)->char);

        (new Parser($h))->feed("\x1b[?2026l" . 'BBBB');
        // Window close replays the tail queue, then the post-close writes
        // land from the cursor's current column (1) forward.
        self::assertSame('B', $h->buffer->cell(0, 1)->char);
        self::assertSame('A', $h->buffer->cell(0, 0)->char);
    }

    public function testSyncEraseFloodFlushesAtTheMutationCeiling(): void
    {
        $cap = $this->syncMutationCap();
        // ED-2 blanks the whole grid: each call appends cols*rows mutations
        // by reference through EraseHandler, bypassing the putCell() choke.
        $fills = (int) ceil($cap / (20 * 5)) + 10;
        $h = $this->feedHandler("\x1b[?2026h" . str_repeat("\x1b[2J", $fills));

        self::assertLessThanOrEqual($cap, $this->queueLength($h));
    }

    public function testDebugHandlerLogIsBounded(): void
    {
        // sap-20 fold (2026-11-13): the fork's bounded-by-default became the
        // lib's opt-in ring — pass the ceiling explicitly to keep the
        // residency promise this row pins.
        $d = new DebugHandler(maxLogRows: DebugHandler::MAX_LOG_ROWS);
        $parser = new Parser($d);
        $parser->feed('Z');
        $parser->feed(str_repeat('x', DebugHandler::MAX_LOG_ROWS + DebugHandler::EVICT_GRACE_ROWS + 1));

        self::assertLessThanOrEqual(
            DebugHandler::MAX_LOG_ROWS + DebugHandler::EVICT_GRACE_ROWS,
            \count($d->log),
            'accumulator must not grow past the eviction ceiling'
        );
        self::assertGreaterThanOrEqual(DebugHandler::MAX_LOG_ROWS, \count($d->log));
        // Drop-oldest: the very first recorded row was evicted.
        self::assertSame('x', $d->log[0]['detail']);
        self::assertSame([], $d->filter('execute'));
    }

    public function testCombiningMarkFloodStaysAtThePerCellCeiling(): void
    {
        // audit sugar-vterm-025 (vterm-14): a bare-mark flood onto one host
        // cell — 600 KiB of marks — cannot grow the cell. The FIRST marks
        // win (grave burst retained to the ceiling, the acute burst after it
        // entirely dropped), in BOTH engines, and the dropped marks never
        // touch the cursor.
        $grave = "\xcc\x80"; // U+0300 COMBINING GRAVE ACCENT
        $acute = "\xcc\x81"; // U+0301 COMBINING ACUTE ACCENT
        $bytes = 'e' . str_repeat($grave, 200_000) . str_repeat($acute, 100);

        $emulator = FullTerminal::new(20, 5);
        $emulator->feed($bytes);
        self::assertSame(
            str_repeat($grave, Cell::MAX_COMBINING_PER_CELL),
            $emulator->screen()->cell(0, 0)->combining,
            'emulator cell must retain exactly the first MAX_COMBINING_PER_CELL marks',
        );
        self::assertSame(1, $emulator->cursor()->col, 'dropped marks must not move the cursor');

        $renderer = RendererTerminal::new(20, 5);
        $renderer->feed($bytes);
        $host = Screen::fromBuffer($renderer->snapshot()->grid)->cell(0, 0);
        self::assertSame(
            'e' . str_repeat($grave, Cell::MAX_COMBINING_PER_CELL),
            $host->char,
            'renderer display string must cap at the same shared ceiling',
        );
        self::assertSame(1, $renderer->cursor()->col, 'dropped marks must not move the renderer cursor');
    }
}
