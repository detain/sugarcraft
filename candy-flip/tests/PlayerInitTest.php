<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Frame;
use SugarCraft\Flip\Player;
use SugarCraft\Flip\Renderer;
use SugarCraft\Core\Cmd;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Player::init() and Player::subscriptions().
 */
final class PlayerInitTest extends TestCase
{
    private function frames(int $n): array
    {
        $f = [];
        for ($i = 0; $i < $n; $i++) {
            $f[] = new Frame([[[($i * 30) % 255, 0, 0]]], delay: 10);
        }
        return $f;
    }

    /**
     * init() must return null when the frame list is empty.
     */
    public function testInitReturnsNullForEmptyFrames(): void
    {
        $p = new Player([]);
        $this->assertNull($p->init());
    }

    /**
     * init() must return null when the player starts in paused state.
     */
    public function testInitReturnsNullWhenPaused(): void
    {
        $p = new Player($this->frames(3), paused: true);
        $this->assertNull($p->init());
    }

    /**
     * init() must return a tick closure when frames exist and playback is not paused.
     */
    public function testInitReturnsTickForActivePlayback(): void
    {
        $p = new Player($this->frames(3));
        $result = $p->init();
        $this->assertNotNull($result);
        $this->assertInstanceOf(\Closure::class, $result);

        // Invoking the closure yields a TickRequest with the correct interval.
        $tickRequest = $result();
        $this->assertInstanceOf(\SugarCraft\Core\TickRequest::class, $tickRequest);
        // Frame delay is 10 centiseconds = 0.1 seconds.
        $this->assertEqualsWithDelta(0.1, $tickRequest->seconds, 0.001);
    }

    /**
     * subscriptions() always returns null (no async subscriptions needed).
     */
    public function testSubscriptionsReturnsNull(): void
    {
        $p = new Player($this->frames(3));
        $this->assertNull($p->subscriptions());
    }

    /**
     * glyphOnly() density path: when adjacent cells share the same color in
     * density preset, only the glyph (no SGR) must be emitted.
     * This is the "run coalescing" optimisation tested for SOLID, now for DENSITY.
     */
    public function testGlyphOnlyDensityCoalescesRuns(): void
    {
        // Three cells in a row, all the same mid-gray colour.
        // In density mode the ramp index is identical for all three,
        // so the second and third cell should go through glyphOnly().
        $f = new Frame([[[128, 128, 128], [128, 128, 128], [128, 128, 128]]]);
        $r = Renderer::withConstraints(1, 3);
        $out = $r->renderFrame($f, Renderer::PRESET_DENSITY);

        // Strip all ANSI escapes to count raw glyph characters.
        $glyphs = preg_replace('/\033\[[0-9;]+m/', '', $out);
        // Three cells → three glyph characters (one per cell).
        $this->assertSame(3, strlen($glyphs),
            'Three same-luminance cells in density mode must emit three glyphs');

        // The full SGR+glyph for the first cell appears once.
        // Luminance for 128,128,128 ≈ 127.5 → ramp index ≈ 5 of 10 → '@'.
        // Only the FIRST cell should have the full truecolor SGR; cells 2+ use glyphOnly.
        $sgrCount = preg_match_all('/\033\[38;2;128;128;128m/', $out);
        $this->assertSame(1, $sgrCount,
            'Full SGR must appear only once; subsequent cells use glyphOnly');
    }

    /**
     * Player::view() output must include a status line even when the renderer
     * is constrained and clamps the output — the status line is always appended.
     */
    public function testViewAlwaysIncludesStatusLine(): void
    {
        $f = new Frame([[[255, 0, 0], [0, 255, 0], [0, 0, 255]]]);
        $r = Renderer::withConstraints(1, 2); // clamp columns to 2
        $p = new Player([$f], renderer: $r);
        $view = $p->view();

        // Status line contains "frame 1/1" and "solid" and "playing".
        $this->assertStringContainsString('frame 1/1', $view);
        $this->assertStringContainsString('playing', $view);
    }

    /**
     * When paused is true, init() returns null and the player stays paused.
     * update(TickMsg) must also not advance the index when paused.
     */
    public function testTickDoesNotAdvanceWhenPaused(): void
    {
        $p = new Player($this->frames(3), paused: true);
        $this->assertNull($p->init());
        [$p2, $cmd] = $p->update(new \SugarCraft\Flip\TickMsg());
        $this->assertSame(0, $p2->index,
            'TickMsg must not advance index when paused');
        $this->assertNull($cmd);
    }
}
