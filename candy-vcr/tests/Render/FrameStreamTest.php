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
use SugarCraft\Vterm\Terminal;

/**
 * Tests for FrameStream playback speed scaling.
 */
final class FrameStreamTest extends TestCase
{
    /**
     * With playbackSpeed=2.0 and 500ms between events, the virtual gap the
     * frame clock carries is halved (250ms). Since finding 052 the raw stream
     * emits only CHANGED frames, so the observable contract is the timestamp
     * distance between the two emits, not the count of duplicates.
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

        // The grid moves exactly twice ('a' then 'b'); the 2x speed shows up
        // as the 0.25s distance between their timestamps (was 0.5s of frames
        // padded with duplicates pre-052).
        $this->assertGreaterThanOrEqual(2, count($snapshots), 'Both changed frames must be emitted');
        $gap = $snapshots[1]->time - $snapshots[0]->time;
        $this->assertLessThan(0.35, $gap, 'With 2x playback speed the inter-frame gap must be ~250ms');
        $this->assertGreaterThan(0.15, $gap, 'The gap must still carry real scaled time');
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

        // Without speedup the two emits sit 500ms apart on the frame clock.
        $this->assertGreaterThanOrEqual(2, count($snapshots));
        $gap = $snapshots[1]->time - $snapshots[0]->time;
        $this->assertGreaterThan(0.4, $gap, 'Without speedup the emitted frames stay 500ms apart');
        $this->assertLessThan(0.6, $gap);
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

        // With 0.5x speed the 250ms tape gap becomes 500ms of frame clock.
        $this->assertGreaterThanOrEqual(2, count($snapshots));
        $gap = $snapshots[1]->time - $snapshots[0]->time;
        $this->assertGreaterThan(0.4, $gap, 'With 0.5x playback speed the gap doubles to ~500ms');
        $this->assertLessThan(0.6, $gap);
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
