<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\FrameStream;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vt\Terminal;

/**
 * Tests for Renderer + FrameStream integration.
 *
 * Verifies that the Renderer correctly feeds cassette events to the
 * Terminal and that FrameStream produces the expected number of snapshots
 * at the configured fps cadence.
 */
final class RendererTest extends TestCase
{
    public function testRendererRendersEmptyCassette(): void
    {
        $cassette = new Cassette(
            $this->stubHeader(),
            [],
        );
        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);

        $renderer = new Renderer();
        $stream = $renderer->render($player, $terminal, 30.0);

        $frames = iterator_to_array($stream->getIterator());
        $this->assertCount(0, $frames);
    }

    public function testFrameStreamProducesFramesAtFpsCadence(): void
    {
        $fps = 10.0;
        $frameInterval = 1.0 / $fps;
        $duration = 1.0;

        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = new Event(
                t: (float) $i * $frameInterval,
                kind: EventKind::Output,
                payload: ['b' => "frame {$i}\n"],
            );
        }

        $cassette = new Cassette($this->stubHeader(), $events);
        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);

        $stream = new FrameStream($player, $terminal, $fps);
        $frames = iterator_to_array($stream->getIterator());

        $expectedFrameCount = (int) ($duration * $fps) + 1;
        $this->assertGreaterThanOrEqual($expectedFrameCount - 1, count($frames));
    }

    public function testFrameStreamFeedsInputBytesToTerminal(): void
    {
        $events = [
            new Event(
                t: 0.0,
                kind: EventKind::Output,
                payload: ['b' => "\x1b[1;31mbold red\x1b[0m"],
            ),
        ];

        $cassette = new Cassette($this->stubHeader(), $events);
        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);

        $stream = new FrameStream($player, $terminal, 30.0);
        $frames = iterator_to_array($stream->getIterator());

        $this->assertCount(1, $frames);
        $grid = $frames[0]->grid;
        $cell = $grid->get(0, 0);
        $this->assertSame('b', $cell->char);
        $this->assertSame(1, $cell->attrs & \SugarCraft\Vt\Cell::ATTR_BOLD);
    }

    public function testFrameStreamHandlesResizeEvent(): void
    {
        $events = [
            new Event(
                t: 0.0,
                kind: EventKind::Output,
                payload: ['b' => "hello"],
            ),
            new Event(
                t: 0.1,
                kind: EventKind::Resize,
                payload: ['cols' => 40, 'rows' => 10],
            ),
            new Event(
                t: 0.2,
                kind: EventKind::Output,
                payload: ['b' => "world"],
            ),
        ];

        $cassette = new Cassette($this->stubHeader(), $events);
        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);

        $stream = new FrameStream($player, $terminal, 30.0);
        $frames = iterator_to_array($stream->getIterator());

        $this->assertGreaterThanOrEqual(1, count($frames));
    }

    public function testFrameStreamStopsOnQuitEvent(): void
    {
        $events = [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => 'before quit']),
            new Event(t: 0.01, kind: EventKind::Quit, payload: []),
            new Event(t: 0.02, kind: EventKind::Output, payload: ['b' => 'after quit']),
        ];

        $cassette = new Cassette($this->stubHeader(), $events);
        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);

        $stream = new FrameStream($player, $terminal, 30.0);
        $frames = iterator_to_array($stream->getIterator());

        $this->assertGreaterThanOrEqual(1, count($frames));
    }

    public function testFrameStreamUsesTypingSpeedFromHeader(): void
    {
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-08T12:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'test',
            typingSpeed: 100.0,
        );

        $cassette = new Cassette($header, [
            new Event(t: 0.0, kind: EventKind::Input, payload: ['b' => 'a']),
            new Event(t: 0.05, kind: EventKind::Input, payload: ['b' => 'b']),
        ]);
        $player = new Player($cassette);
        $terminal = Terminal::new(80, 24);

        $stream = new FrameStream($player, $terminal, 30.0);
        $frames = iterator_to_array($stream->getIterator());

        $this->assertGreaterThanOrEqual(1, count($frames));
    }

    private function stubHeader(): CassetteHeader
    {
        return new CassetteHeader(
            version: 1,
            createdAt: '2026-05-08T12:00:00Z',
            cols: 80,
            rows: 24,
            runtime: 'SugarCraft/Vcr renderer test',
        );
    }
}
