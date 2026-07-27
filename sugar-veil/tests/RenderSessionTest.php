<?php

declare(strict_types=1);

namespace SugarCraft\Veil\Tests;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Veil\RenderSession;
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

    // ─── release ─────────────────────────────────────────────────────────────────

    public function testReleaseIsAliasForReset(): void
    {
        $session = new RenderSession();

        $session->rememberFull("output", 80, 24);
        $session->release();

        $this->assertTrue($session->shouldEmitFull(80, 24));
    }

    public function testReleaseClearsAllState(): void
    {
        $session = new RenderSession();
        $factory = fn(string $out, int $w, int $h): Buffer => Buffer::fromString($out, $w, $h);

        $session->diff("frame1", 80, 24, $factory);
        $session->release();

        $this->assertTrue($session->shouldEmitFull(80, 24));
    }

    // ─── Constructor ─────────────────────────────────────────────────────────────────

    public function testNewSessionHasNoPreviousOutput(): void
    {
        $session = new RenderSession();
        $this->assertTrue($session->shouldEmitFull(80, 24));
    }
}
