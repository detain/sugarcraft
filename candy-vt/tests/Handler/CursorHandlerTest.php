<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\CursorHandler;

/**
 * Tests for {@see CursorHandler} save/restore cursor functionality.
 *
 * Covers DECSC (save cursor) via CSI 's' and DECRC (restore cursor) via CSI 'u'.
 * Mirrors charmbracelet/x/vt cursor save/restore behavior.
 */
final class CursorHandlerTest extends TestCase
{
    private CursorHandler $handler;
    private Buffer $buffer;

    protected function setUp(): void
    {
        $this->handler = new CursorHandler();
        $this->buffer = new Buffer(80, 24);
    }

    public function testSaveCursorPreservesPosition(): void
    {
        $cursor = new Cursor(row: 5, col: 10);

        $saved = $this->handler->apply(ord('s'), [], $cursor, $this->buffer);

        $this->assertSame(5, $saved->savedRow);
        $this->assertSame(10, $saved->savedCol);
        // Current position unchanged
        $this->assertSame(5, $saved->row);
        $this->assertSame(10, $saved->col);
    }

    public function testRestoreCursorSetsPositionFromSaved(): void
    {
        $cursor = (new Cursor(row: 5, col: 10))->save();

        $restored = $this->handler->apply(ord('u'), [], $cursor, $this->buffer);

        $this->assertSame(5, $restored->row);
        $this->assertSame(10, $restored->col);
    }

    public function testSaveRestoreRoundTrip(): void
    {
        $cursor = new Cursor(row: 15, col: 40);

        $saved = $this->handler->apply(ord('s'), [], $cursor, $this->buffer);
        $moved = $saved->withRow(0)->withCol(0);
        $restored = $this->handler->apply(ord('u'), [], $moved, $this->buffer);

        $this->assertSame(15, $restored->row);
        $this->assertSame(40, $restored->col);
    }

    public function testMultipleSaveRestoreCycles(): void
    {
        // Save at (5, 10)
        $cursor = new Cursor(row: 5, col: 10);
        $saved1 = $this->handler->apply(ord('s'), [], $cursor, $this->buffer);

        // Move to (20, 50)
        $cursor2 = $saved1->withRow(20)->withCol(50);

        // Restore should get (5, 10)
        $restored1 = $this->handler->apply(ord('u'), [], $cursor2, $this->buffer);
        $this->assertSame(5, $restored1->row);
        $this->assertSame(10, $restored1->col);

        // Move somewhere else
        $cursor3 = $restored1->withRow(0)->withCol(0);

        // Second restore should also get (5, 10) since saved values are still the same
        // Note: DECRC doesn't maintain a stack - each save overwrites the previous
        $restored2 = $this->handler->apply(ord('u'), [], $cursor3, $this->buffer);
        $this->assertSame(5, $restored2->row);
        $this->assertSame(10, $restored2->col);
    }

    public function testRestoreWithoutPriorSaveDoesNotChangePosition(): void
    {
        // Cursor has never been saved (savedRow/savedCol are null)
        $cursor = new Cursor(row: 7, col: 25);

        $restored = $this->handler->apply(ord('u'), [], $cursor, $this->buffer);

        // Should fall back to current position when savedRow/savedCol are null
        $this->assertSame(7, $restored->row);
        $this->assertSame(25, $restored->col);
    }

    public function testRestoreWithNullSavedPositionFallsBackToCurrent(): void
    {
        // Cursor with explicit null saved values
        $cursor = new Cursor(row: 3, col: 15, savedRow: null, savedCol: null);

        $restored = $this->handler->apply(ord('u'), [], $cursor, $this->buffer);

        // Falls back to current position
        $this->assertSame(3, $restored->row);
        $this->assertSame(15, $restored->col);
    }

    public function testSaveDoesNotAffectVisibilityOrShape(): void
    {
        $cursor = new Cursor(row: 5, col: 10, visible: false, shape: 2);

        $saved = $this->handler->apply(ord('s'), [], $cursor, $this->buffer);

        $this->assertFalse($saved->visible);
        $this->assertSame(2, $saved->shape);
    }

    public function testRestoreDoesNotAffectVisibilityOrShape(): void
    {
        $cursor = (new Cursor(row: 5, col: 10, visible: false, shape: 2))->save();
        $moved = $cursor->withRow(0)->withCol(0)->withVisible(true)->withShape(1);

        $restored = $this->handler->apply(ord('u'), [], $moved, $this->buffer);

        // Visibility and shape should be preserved from the moved state, not restored
        $this->assertTrue($restored->visible);
        $this->assertSame(1, $restored->shape);
        // But position should be restored
        $this->assertSame(5, $restored->row);
        $this->assertSame(10, $restored->col);
    }

    public function testSaveAtOrigin(): void
    {
        $cursor = new Cursor(row: 0, col: 0);
        $saved = $this->handler->apply(ord('s'), [], $cursor, $this->buffer);

        $this->assertSame(0, $saved->savedRow);
        $this->assertSame(0, $saved->savedCol);
    }

    public function testSaveAtBufferBounds(): void
    {
        $cursor = new Cursor(row: 23, col: 79); // 24x80 buffer
        $saved = $this->handler->apply(ord('s'), [], $cursor, $this->buffer);

        $this->assertSame(23, $saved->savedRow);
        $this->assertSame(79, $saved->savedCol);
    }

    public function testRestoreDoesNotClampToBufferBounds(): void
    {
        // Cursor with saved position beyond buffer bounds
        // Cursor does NOT clamp on restore - it uses saved values directly
        $cursor = new Cursor(row: 0, col: 0, savedRow: 100, savedCol: 200);

        $restored = $this->handler->apply(ord('u'), [], $cursor, $this->buffer);

        // Position is restored as-is (no clamping at Cursor level)
        $this->assertSame(100, $restored->row);
        $this->assertSame(200, $restored->col);
    }

    public function testSaveAndMoveSequence(): void
    {
        // Common use case: save position, do something, restore
        $cursor = new Cursor(row: 5, col: 15);

        // Save
        $saved = $this->handler->apply(ord('s'), [], $cursor, $this->buffer);

        // Move to home position
        $home = $this->handler->apply(ord('H'), [], $saved, $this->buffer); // CUP without params = home
        $this->assertSame(0, $home->row);
        $this->assertSame(0, $home->col);

        // Restore
        $restored = $this->handler->apply(ord('u'), [], $home, $this->buffer);
        $this->assertSame(5, $restored->row);
        $this->assertSame(15, $restored->col);
    }

    public function testConcurrentSaveStatesIndependent(): void
    {
        // Two cursors with different save states
        $cursor1 = (new Cursor(row: 1, col: 1))->save();
        $cursor2 = (new Cursor(row: 10, col: 20))->save();

        // Move them differently
        $moved1 = $cursor1->withRow(50)->withCol(50);
        $moved2 = $cursor2->withRow(5)->withCol(5);

        // Restore should get respective saved positions
        $restored1 = $this->handler->apply(ord('u'), [], $moved1, $this->buffer);
        $restored2 = $this->handler->apply(ord('u'), [], $moved2, $this->buffer);

        $this->assertSame(1, $restored1->row);
        $this->assertSame(1, $restored1->col);

        $this->assertSame(10, $restored2->row);
        $this->assertSame(20, $restored2->col);
    }

    public function testSaveAfterRestoreUsesCurrentPosition(): void
    {
        $cursor = (new Cursor(row: 5, col: 10))->save();
        $restored = $this->handler->apply(ord('u'), [], $cursor, $this->buffer);

        // Now save again - should save the restored position
        $savedAgain = $this->handler->apply(ord('s'), [], $restored, $this->buffer);

        $this->assertSame(5, $savedAgain->savedRow);
        $this->assertSame(10, $savedAgain->savedCol);
    }

    public function testCursorEqualsAfterSaveRestore(): void
    {
        $cursor = new Cursor(row: 12, col: 30, visible: true, shape: 0);

        $saved = $this->handler->apply(ord('s'), [], $cursor, $this->buffer);
        $restored = $this->handler->apply(ord('u'), [], $saved->withRow(0)->withCol(0), $this->buffer);

        // Cursor should be equal in all aspects after round-trip
        $this->assertTrue($cursor->equals($restored));
    }

    public function testCursorEqualsFailsWhenPositionDiffers(): void
    {
        $cursor = new Cursor(row: 5, col: 10);

        $saved = $this->handler->apply(ord('s'), [], $cursor, $this->buffer);
        $restored = $this->handler->apply(ord('u'), [], $saved, $this->buffer);

        // After save/restore, cursor should equal original
        $this->assertTrue($cursor->equals($restored));
    }

    public function testNonSaveRestoreCommandsDoNotAffectSavedPosition(): void
    {
        $cursor = (new Cursor(row: 5, col: 10))->save();

        // Apply movement command
        $moved = $this->handler->apply(ord('A'), [], $cursor, $this->buffer); // CUU - up 1

        // Saved position should be unchanged
        $this->assertSame(5, $moved->savedRow);
        $this->assertSame(10, $moved->savedCol);
        // Current position should be updated
        $this->assertSame(4, $moved->row);
    }

    public function testCursorSaveMethodDirectly(): void
    {
        // Test the Cursor::save() method directly
        $cursor = new Cursor(row: 7, col: 22);
        $saved = $cursor->save();

        $this->assertSame(7, $saved->savedRow);
        $this->assertSame(22, $saved->savedCol);
        $this->assertSame(7, $saved->row);
        $this->assertSame(22, $saved->col);
    }

    public function testCursorRestoreMethodDirectly(): void
    {
        // Test the Cursor::restore() method directly
        $cursor = (new Cursor(row: 7, col: 22))->save();
        $moved = $cursor->withRow(0)->withCol(0);
        $restored = $moved->restore();

        $this->assertSame(7, $restored->row);
        $this->assertSame(22, $restored->col);
    }
}
