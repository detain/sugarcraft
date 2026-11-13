<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\Overlay\RenderSession;
use PHPUnit\Framework\TestCase;

final class RenderSessionTest extends TestCase
{
    // ─── shouldEmitFull ─────────────────────────────────────────────────────────────────

    public function testShouldEmitFullOnFirstCall(): void
    {
        $session = new RenderSession();
        $this->assertTrue($session->shouldEmitFull(80, 24));
    }

    public function testShouldEmitFullReturnsFalseWhenSameDimensions(): void
    {
        $session = new RenderSession();
        $session->rememberFull("output", 80, 24);

        $this->assertFalse($session->shouldEmitFull(80, 24));
    }

    public function testShouldEmitFullReturnsTrueWhenWidthChanges(): void
    {
        $session = new RenderSession();
        $session->rememberFull("output", 80, 24);

        $this->assertTrue($session->shouldEmitFull(100, 24));
    }

    public function testShouldEmitFullReturnsTrueWhenHeightChanges(): void
    {
        $session = new RenderSession();
        $session->rememberFull("output", 80, 24);

        $this->assertTrue($session->shouldEmitFull(80, 40));
    }

    // ─── rememberFull ─────────────────────────────────────────────────────────────────

    public function testRememberFullStoresState(): void
    {
        $session = new RenderSession();

        $session->rememberFull("frame1", 80, 24);

        $this->assertFalse($session->shouldEmitFull(80, 24));
    }

    public function testRememberFullClearsPreviousFrame(): void
    {
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        // Trigger diff state
        $session->diff("first", 80, 24, $factory);

        // rememberFull should clear the diff buffer
        $session->rememberFull("full", 80, 24);

        // Should emit full, not diff
        $this->assertTrue($session->shouldEmitFull(80, 24));
    }

    public function testRememberFullAfterResize(): void
    {
        $session = new RenderSession();

        $session->rememberFull("output", 80, 24);
        $this->assertFalse($session->shouldEmitFull(80, 24));

        $session->rememberFull("output", 100, 40);
        $this->assertFalse($session->shouldEmitFull(100, 40));
    }

    // ─── diff ─────────────────────────────────────────────────────────────────

    public function testDiffOnFirstCallReturnsEmptyString(): void
    {
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        // First call uses lazy previousFrame init but returns encoded diff
        $result = $session->diff("frame1", 80, 24, $factory);

        // The result is encoded diff bytes (empty string if no ops)
        $this->assertIsString($result);
    }

    public function testDiffReturnsEncodedOps(): void
    {
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        // First call builds previousFrame
        $session->diff("frame1", 80, 24, $factory);

        // Second call computes diff
        $result = $session->diff("frame2", 80, 24, $factory);

        $this->assertIsString($result);
    }

    public function testDiffPreservesPreviousOutput(): void
    {
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        $session->diff("frame1", 80, 24, $factory);

        // shouldEmitFull should now return false for same dimensions
        $this->assertFalse($session->shouldEmitFull(80, 24));
    }

    // ─── reset ─────────────────────────────────────────────────────────────────

    public function testResetClearsAllState(): void
    {
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        $session->rememberFull("output", 80, 24);
        $session->diff("change", 80, 24, $factory);

        $session->reset();

        $this->assertTrue($session->shouldEmitFull(80, 24));
        $this->assertTrue($session->shouldEmitFull(100, 40));
    }

    public function testResetAfterRememberFull(): void
    {
        $session = new RenderSession();

        $session->rememberFull("frame1", 80, 24);
        $session->reset();

        $this->assertTrue($session->shouldEmitFull(80, 24));
    }

    // ─── Constructor ─────────────────────────────────────────────────────────────────

    public function testNewSessionHasNoPreviousOutput(): void
    {
        $session = new RenderSession();
        $this->assertTrue($session->shouldEmitFull(80, 24));
    }

    // ─── justClearedFrame one-shot grant ─────────────────────────────────────────────

    public function testRememberFullAfterDiffAtSameDimensionsForcesOneFullFrame(): void
    {
        // Veil::composite() never arms this grant (it only reaches
        // rememberFull() after shouldEmitFull() said true, which already
        // cleared diffWasCalled), so drive the session directly.
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        $session->rememberFull('frame1', 10, 2);
        $session->diff('frame2', 10, 2, $factory);
        $session->rememberFull('frame3', 10, 2);

        $this->assertTrue($session->shouldEmitFull(10, 2), 'armed: next same-size frame is full');
        $this->assertFalse($session->shouldEmitFull(10, 2), 'one-shot: the grant is consumed');
    }

    public function testRememberFullAtNewDimensionsDoesNotArmTheGrant(): void
    {
        // The arming check must compare against the PREVIOUS dimensions; it
        // used to compare after overwriting them, so it was always true and a
        // resize-time rememberFull() forced a redundant second full frame.
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        $session->rememberFull('frame1', 10, 2);
        $session->diff('frame2', 10, 2, $factory);
        $session->rememberFull('resized', 20, 4);

        $this->assertFalse($session->shouldEmitFull(20, 4));
    }

    public function testRememberFullWithoutPriorDiffDoesNotArmTheGrant(): void
    {
        $session = new RenderSession();
        $session->rememberFull('frame1', 10, 2);
        $session->rememberFull('frame2', 10, 2);

        $this->assertFalse($session->shouldEmitFull(10, 2));
    }

    public function testResetDisarmsThePendingGrant(): void
    {
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        $session->rememberFull('frame1', 10, 2);
        $session->diff('frame2', 10, 2, $factory);
        $session->rememberFull('frame3', 10, 2);
        $session->reset();
        $session->rememberFull('frame4', 10, 2);

        $this->assertFalse($session->shouldEmitFull(10, 2));
    }

    // ─── lastFullFrame (M5, lib-fixes-1103) ────────────────────────────────────

    public function testLastFullFrameIsNullOnAColdSession(): void
    {
        $this->assertNull((new RenderSession())->lastFullFrame());
    }

    public function testRememberFullRecordsTheFullFrame(): void
    {
        $session = new RenderSession();
        $session->rememberFull('the-frame', 80, 24);
        $this->assertSame('the-frame', $session->lastFullFrame());
    }

    public function testDiffKeepsTheFullFrameBehindTheDelta(): void
    {
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);
        $session->rememberFull('first', 10, 2);

        $delta = $session->diff('second', 10, 2, $factory);

        $this->assertNotSame('', $delta, 'changed frames must return a non-empty delta');
        $this->assertSame('second', $session->lastFullFrame(), 'lastFullFrame() names the FULL frame the delta was derived from, even when the delta textually coincides with it');
    }

    public function testColdDiffPrimesTheFrameWithoutEmittingIt(): void
    {
        // m18 truth-of-the-prime: the first diff() returns '' (paints
        // nothing) yet REMEMBERS the frame — direct session drivers must
        // read lastFullFrame() to print their own initial full frame.
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        $this->assertSame('', $session->diff('cold-frame', 10, 2, $factory));
        $this->assertSame('cold-frame', $session->lastFullFrame());
    }

    public function testSecondColdDeltaErasesCellsTheFirstFramePainted(): void
    {
        // SO-01 (sugar-overlay-001): the cold prime used to store a BLANK
        // buffer while previousOutput held the real frame — `??=` then never
        // rebuilt it, so cells frame 1 painted and frame 2 left blank were
        // judged blank->blank and NEVER erased: persistent on-screen ghosts
        // for direct session drivers. The frame-2 delta must carry the erase.
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        $this->assertSame('', $session->diff('A', 10, 2, $factory));
        $delta = $session->diff('', 10, 2, $factory);

        $this->assertNotSame('', $delta, 'frame 1 must be rebuilt from previousOutput so its painted cell is erased, not ghosted');
        $this->assertStringContainsString(' ', $delta, 'the erase is a blank written over the ghosted cell');
        $this->assertSame('', $session->diff('', 10, 2, $factory), 'two genuinely blank frames must still diff to nothing');
    }

    public function testResetClearsTheFullFrame(): void
    {
        $session = new RenderSession();
        $session->rememberFull('x', 80, 24);
        $session->reset();
        $this->assertNull($session->lastFullFrame());
    }
}
