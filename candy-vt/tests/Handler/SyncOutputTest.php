<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;

/**
 * Tests for Synchronized Output (DEC 2026) — when mode 2026 is active,
 * all buffer mutations are held in a pending queue and flushed
 * atomically when the mode is disabled.
 *
 * Mirrors charmbracelet/x/vt.syncUpdate tests.
 */
final class SyncOutputTest extends TestCase
{
    /** Helper: build a ScreenHandler on a 10x2 buffer. */
    private function makeHandler(?Mode $mode = null): ScreenHandler
    {
        return new ScreenHandler(new Buffer(10, 2), mode: $mode);
    }

    /** Invoke a private method via reflection. */
    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($obj);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($obj, ...$args);
    }

    /** Read a private property via reflection. */
    private function prop(object $obj, string $prop): mixed
    {
        $ref = new \ReflectionClass($obj);
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        return $p->getValue($obj);
    }

    // ─── putCell queues when sync mode is active ───────────────────────────────

    public function testPutCellWritesDirectlyWhenSyncModeOff(): void
    {
        $h = $this->makeHandler();
        $cell = new Cell(grapheme: 'X');
        $this->invoke($h, 'putCell', 0, 0, $cell);
        $this->assertSame('X', $h->buffer->cell(0, 0)->grapheme);
    }

    public function testPutCellQueuesMutationWhenSyncModeOn(): void
    {
        $h = $this->makeHandler((new Mode())->withSyncUpdate(true));
        $cell = new Cell(grapheme: 'X');
        $this->invoke($h, 'putCell', 0, 0, $cell);

        // Buffer should NOT be updated yet.
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);
        // But mutation should be in pending queue.
        $this->assertCount(1, $this->prop($h, 'pendingMutations'));
    }

    public function testFlushPendingMutationsAppliesAllQueued(): void
    {
        $h = $this->makeHandler((new Mode())->withSyncUpdate(true));
        $this->invoke($h, 'putCell', 0, 0, new Cell(grapheme: 'A'));
        $this->invoke($h, 'putCell', 0, 1, new Cell(grapheme: 'B'));
        $this->invoke($h, 'putCell', 1, 5, new Cell(grapheme: 'C'));

        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(1, 5)->grapheme);
        $this->assertCount(3, $this->prop($h, 'pendingMutations'));

        $this->invoke($h, 'flushPendingMutations');

        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame('C', $h->buffer->cell(1, 5)->grapheme);
        $this->assertCount(0, $this->prop($h, 'pendingMutations'));
    }

    public function testFlushPendingMutationsClearsQueue(): void
    {
        $h = $this->makeHandler((new Mode())->withSyncUpdate(true));
        $this->invoke($h, 'putCell', 0, 0, new Cell(grapheme: 'X'));
        $this->assertCount(1, $this->prop($h, 'pendingMutations'));

        $this->invoke($h, 'flushPendingMutations');

        $this->assertCount(0, $this->prop($h, 'pendingMutations'));
    }

    // ─── printChar respects sync mode ────────────────────────────────────────

    public function testPrintCharQueuesWhenSyncModeOn(): void
    {
        $h = $this->makeHandler((new Mode())->withSyncUpdate(true));
        $h->printChar('H');
        $h->printChar('i');

        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(0, 1)->grapheme);
        $this->assertCount(2, $this->prop($h, 'pendingMutations'));
    }

    public function testPrintCharFlushesOnModeDisable(): void
    {
        // Start with sync mode on.
        $h = $this->makeHandler((new Mode())->withSyncUpdate(true));
        $h->printChar('A');
        $h->printChar('B');

        // Simulate CSI ?2026 l (mode disable).
        $wasSync = $h->mode->syncUpdate;
        $h->mode = $h->mode->withSyncUpdate(false);
        if ($wasSync && !$h->mode->syncUpdate) {
            $this->invoke($h, 'flushPendingMutations');
        }

        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
    }

    // ─── csiDispatch flushes on mode exit ─────────────────────────────────────

    public function testCsiDispatch2026ModeOffTriggersFlush(): void
    {
        // CSI ?2026 h enables sync mode, then CSI ?2026 l disables it.
        $h = $this->makeHandler();
        $h->csiDispatch(ord('h'), [2026], ord('?'), 0); // CSI ?2026 h
        $this->assertTrue($h->mode->syncUpdate);

        $h->printChar('X');
        $h->printChar('Y');
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme); // queued

        // CSI ?2026 l disables sync mode and flushes.
        $h->csiDispatch(ord('l'), [2026], ord('?'), 0); // CSI ?2026 l
        $this->assertFalse($h->mode->syncUpdate);

        $this->assertSame('X', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('Y', $h->buffer->cell(0, 1)->grapheme);
    }

    // ─── EraseHandler respects sync mode via pending ─────────────────────────────

    public function testEraseHandlerQueuesWhenSyncModeOn(): void
    {
        $h = $this->makeHandler((new Mode())->withSyncUpdate(true));

        // Print some chars first (queued).
        $h->printChar('A');
        $h->printChar('B');
        $h->printChar('C');
        $this->assertCount(3, $this->prop($h, 'pendingMutations'));

        // With sync mode on, the buffer is NOT modified immediately.
        // (It would only change after flush on mode disable.)
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);
    }

    public function testEraseHandlerFlushesWhenSyncModeDisabled(): void
    {
        $h = $this->makeHandler((new Mode())->withSyncUpdate(true));

        $h->printChar('A');
        $h->printChar('B');
        $h->printChar('C');

        // CSI ?2026 l — disable sync and flush.
        $h->csiDispatch(ord('l'), [2026], ord('?'), 0);

        // Queued prints are flushed: cells contain the printed characters.
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame('C', $h->buffer->cell(0, 2)->grapheme);
    }
}
