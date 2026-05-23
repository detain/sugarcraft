<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vt\Terminal;

/**
 * Tests for FrameStream playback speed scaling.
 */
final class FrameStreamTest extends TestCase
{
    /**
     * With playbackSpeed=2.0 and 500ms between events, frames should be
     * rendered at 2x speed (250ms scaled time for the 500ms gap).
     * At fps=60, this means ~15 frames instead of ~30 for the same gap.
     */
    public function testPlaybackSpeedHalvesInterEventDelay(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'SugarCraft/Vcr playback speed test',
                playbackSpeed: 2.0,
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
                new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'b']),
            ],
        );

        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);
        $renderer = new Renderer();

        $stream = $renderer->render($player, $terminal, 60.0);

        $snapshots = [];
        foreach ($stream as $snap) {
            $snapshots[] = $snap;
        }

        // With 2.0x speed, the 500ms gap becomes 250ms.
        // At 60fps, 250ms yields approximately 15 frames (250/16.67 ≈ 15).
        // Without speedup, 500ms would yield ~30 frames.
        $this->assertLessThan(25, count($snapshots), 'With 2x playback speed, frame count should be roughly halved');
        $this->assertGreaterThan(10, count($snapshots), 'Should still produce a reasonable number of frames');
    }

    public function testPlaybackSpeedDefaultYieldsNormalFrameCount(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'SugarCraft/Vcr playback speed test',
                // No playbackSpeed set (null)
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
                new Event(t: 0.5, kind: EventKind::Output, payload: ['b' => 'b']),
            ],
        );

        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);
        $renderer = new Renderer();

        $stream = $renderer->render($player, $terminal, 60.0);

        $snapshots = [];
        foreach ($stream as $snap) {
            $snapshots[] = $snap;
        }

        // Without speedup, 500ms at 60fps yields approximately 30 frames
        $this->assertGreaterThan(25, count($snapshots), 'Without speedup, frame count should be higher');
    }

    public function testPlaybackSpeedDoublesSpeedWithSpeedHalf(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'SugarCraft/Vcr playback speed test',
                playbackSpeed: 0.5,
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'a']),
                new Event(t: 0.25, kind: EventKind::Output, payload: ['b' => 'b']),
            ],
        );

        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);
        $renderer = new Renderer();

        $stream = $renderer->render($player, $terminal, 60.0);

        $snapshots = [];
        foreach ($stream as $snap) {
            $snapshots[] = $snap;
        }

        // With 0.5x speed, the 250ms gap becomes 500ms.
        // At 60fps, 500ms yields approximately 30 frames.
        $this->assertGreaterThan(25, count($snapshots), 'With 0.5x playback speed, frame count should be doubled');
    }

    public function testCaptureCursorFlagToggledByHideShowEvents(): void
    {
        // Test Hide then Show - we use two separate cassettes to verify
        // intermediate state without the final Show event interfering.
        $cassetteHide = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'SugarCraft/Vcr',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'A']),
                new Event(t: 0.1, kind: EventKind::Hide, payload: []),
                new Event(t: 0.2, kind: EventKind::Output, payload: ['b' => 'B']),
            ],
        );

        $playerHide = new Player($cassetteHide);
        $terminal = Terminal::new(80, 24);
        $renderer = new Renderer();

        $streamHide = $renderer->render($playerHide, $terminal, 60.0);

        // Consume all frames
        foreach ($streamHide as $_) {
        }

        $this->assertFalse($streamHide->captureCursor, 'After Hide event, captureCursor should be false');

        // Test Show restores cursor visibility
        $cassetteShow = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'SugarCraft/Vcr',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'A']),
                new Event(t: 0.1, kind: EventKind::Hide, payload: []),
                new Event(t: 0.2, kind: EventKind::Output, payload: ['b' => 'B']),
                new Event(t: 0.3, kind: EventKind::Show, payload: []),
                new Event(t: 0.4, kind: EventKind::Output, payload: ['b' => 'C']),
            ],
        );

        $playerShow = new Player($cassetteShow);
        $terminalShow = Terminal::new(80, 24);
        $rendererShow = new Renderer();

        $streamShow = $rendererShow->render($playerShow, $terminalShow, 60.0);

        // Consume all frames
        foreach ($streamShow as $_) {
        }

        $this->assertTrue($streamShow->captureCursor, 'After Show event, captureCursor should be true');
    }

    public function testCaptureCursorTrueByDefault(): void
    {
        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'SugarCraft/Vcr',
            ),
            [
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'A']),
            ],
        );

        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);
        $renderer = new Renderer();

        $stream = $renderer->render($player, $terminal, 60.0);

        // Consume frames
        foreach ($stream as $_) {
        }

        $this->assertTrue($stream->captureCursor, 'captureCursor should be true by default');
    }
}
