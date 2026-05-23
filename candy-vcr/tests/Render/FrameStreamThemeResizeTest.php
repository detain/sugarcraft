<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Render;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Terminal;
use SugarCraft\Vt\Theme;

/**
 * Regression: FrameStream Resize event preserves the terminal's theme.
 *
 * Bug fixed in d070e742 (FrameStream::processResize): the old code
 * rebuilt the terminal with `Terminal::new($cols, $rows)` and dropped
 * the theme arg, so any Resize event silently reset the cassette back
 * to the default basic VGA theme. The fix threads `$terminal->theme()`
 * into the rebuilt instance.
 *
 * Tested end-to-end through the rasterizer: render the default cell of
 * a snapshot before AND after a Resize event using the same GdRasterizer.
 * If the theme were lost the bg pixel would shift from
 * tokyoNight `0x15161e` to basic `0x000000`.
 */
final class FrameStreamThemeResizeTest extends TestCase
{
    public function testBackgroundColorSurvivesAfterResize(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $cassette = new Cassette(
            new CassetteHeader(
                version: 1,
                createdAt: '2026-05-22T00:00:00Z',
                cols: 80,
                rows: 24,
                runtime: 'SugarCraft/Vcr resize-theme test',
            ),
            [
                // Tick 0 — snapshot at default size.
                new Event(t: 0.0, kind: EventKind::Output, payload: ['b' => ' ']),
                // Tick 1 — Resize to 40x10.
                new Event(t: 1.0, kind: EventKind::Resize, payload: ['cols' => 40, 'rows' => 10]),
                // Tick 1.01 — Output a space to make sure the post-resize
                // terminal is exercised.
                new Event(t: 1.01, kind: EventKind::Output, payload: ['b' => ' ']),
            ],
        );

        $player = new Player($cassette);
        $tokyoNight = Theme::tokyoNight();
        $terminal = Terminal::new(80, 24, $tokyoNight);

        $renderer = new Renderer();
        $stream = $renderer->render($player, $terminal, 30.0);

        $snapshots = [];
        foreach ($stream as $snap) {
            $snapshots[] = $snap;
        }
        $this->assertGreaterThanOrEqual(2, count($snapshots), 'Should emit pre- and post-resize snapshots');

        $first = $snapshots[0];
        $lastKey = array_key_last($snapshots);
        $this->assertNotNull($lastKey);
        $last = $snapshots[$lastKey];

        // Confirm the resize actually applied (dimensions shrank).
        $this->assertSame(80, $first->grid->cols, 'pre-resize cols');
        $this->assertSame(40, $last->grid->cols, 'post-resize cols');

        // Rasterize both — same GdRasterizer instance, themed to TokyoNight,
        // so any drift in the post-resize bg indicates the theme was lost.
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono', $tokyoNight);

        $bgFirst = $this->sampleBgPixel($rasterizer->rasterize($first, 8, 16));
        $bgLast = $this->sampleBgPixel($rasterizer->rasterize($last, 8, 16));

        // tokyoNight defaultBg index 0 = 0x15161e. With no-theme regression
        // the default Theme bg index 0 would be 0x000000.
        $this->assertSame(
            $bgFirst,
            $bgLast,
            sprintf('Background must match before and after Resize — got #%06x vs #%06x', $bgFirst, $bgLast),
        );
        $this->assertSame(
            0x15161e,
            $bgFirst,
            sprintf('Pre-resize bg must be TokyoNight 0x15161e — got #%06x', $bgFirst),
        );
        $this->assertSame(
            0x15161e,
            $bgLast,
            sprintf('Post-resize bg must STILL be TokyoNight 0x15161e — got #%06x', $bgLast),
        );
    }

    private function sampleBgPixel(\GdImage $image): int
    {
        // Sample a far-right pixel that should be the default cell bg.
        $w = imagesx($image);
        $h = imagesy($image);
        $x = $w - 1;
        $y = $h - 1;
        $rgb = imagecolorat($image, $x, $y);
        if ($rgb === false) {
            throw new \RuntimeException('imagecolorat returned false');
        }
        $rgba = imagecolorsforindex($image, $rgb);
        imagedestroy($image);
        return ($rgba['red'] << 16) | ($rgba['green'] << 8) | $rgba['blue'];
    }
}
