<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vt\Terminal;
use SugarCraft\Testing\Snapshot\Assertions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-file snapshot tests for ANSI rendering output.
 *
 * Note: candy-vcr produces Snapshot frames (cell grid state), not ANSI bytes.
 * These tests verify the rendering pipeline produces consistent frame output.
 */
final class GoldenRenderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testRendererProducesFrameStream(): void
    {
        // Create a minimal cassette with one output event
        $header = new CassetteHeader(
            version: 1,
            createdAt: '2026-05-31T00:00:00Z',
            cols: 10,
            rows: 3,
            runtime: 'sugarcraft/candy-vt@dev',
        );
        $events = [
            new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => "\x1b[31mRed\x1b[0m"]),
        ];
        $cassette = new Cassette($header, $events);

        $player = new Player($cassette);
        $terminal = Terminal::new(10, 3);
        $renderer = new Renderer();

        $stream = $renderer->render($player, $terminal, 30.0);

        // Collect first frame
        $frames = iterator_to_array($stream);
        $this->assertNotEmpty($frames);

        // Get the first snapshot's grid text representation
        $snapshot = $frames[0];
        $grid = $snapshot->grid;
        $output = '';
        for ($r = 0; $r < min(3, $grid->rows); $r++) {
            for ($c = 0; $c < min(10, $grid->cols); $c++) {
                $cell = $grid->get($r, $c);
                if ($cell->char !== "\0" && $cell->char !== ' ') {
                    $output .= $cell->char;
                }
            }
        }

        Assertions::assertGoldenAnsi(
            $this->fixturesDir . '/renderer-frame.golden',
            $output,
        );
    }
}
