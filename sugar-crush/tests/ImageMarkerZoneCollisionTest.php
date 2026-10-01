<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\ImageOverlay;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Sentinel;

/**
 * Regression guard for the defect that appeared where the two Wave 2 tracks
 * met — tool-result image rendering (crush_feat.md §9 E3) and the mouse/zone
 * chain (§8) — and for the defense-in-depth that outlived it.
 *
 * When `ImageOverlay::MARKER_BASE` was U+E000, the first image in a frame
 * emitted a byte-identical copy of candy-mouse's {@see Sentinel::OPEN} and the
 * second copied {@see Sentinel::CLOSE}; the scanner treated the rest of the
 * frame as one unterminated zone and silently dropped every zone after it.
 * The allocator has since moved to U+E002 + id (a32c4faae PUA-reservation
 * ruling, follow-up 2 of 2), so live markers are disjoint from the sentinel
 * pair by construction. What this guard now pins is the other half of that
 * story: a forged sentinel byte (or any stray Private-Use codepoint) reaching
 * a frame from model/tool text still must not break the scan — the Renderer
 * masks the block out of the copy the SCANNER reads (never the copy sent to
 * the terminal, which still needs its real markers for Program to resolve
 * into paints).
 */
final class ImageMarkerZoneCollisionTest extends TestCase
{
    /**
     * The disjoint-arena property of the two libraries: if this ever stops
     * being true the original collision is back and the reservation docblocks
     * need re-lawyering. (The mask is no longer justified by marker bytes —
     * it is justified by forgery, which the next tests pin.)
     */
    public function testNoImageMarkerIsByteIdenticalToAZoneSentinel(): void
    {
        $this->assertSame(Sentinel::OPEN, "\u{E000}");
        $this->assertSame(Sentinel::CLOSE, "\u{E001}");
        $this->assertNotSame(Sentinel::OPEN, ImageOverlay::marker(0));
        $this->assertNotSame(Sentinel::CLOSE, ImageOverlay::marker(0));
        $this->assertNotSame(Sentinel::OPEN, ImageOverlay::marker(1));
        $this->assertNotSame(Sentinel::CLOSE, ImageOverlay::marker(1));
        $this->assertSame("\u{E002}", ImageOverlay::marker(0));
    }

    /**
     * A real image marker between two zones is inert text to the scanner —
     * both zones survive an unmasked scan (this is what the arena move bought;
     * pre-move, exactly this frame dropped tab2). A forged OPEN sentinel in
     * the same slot still swallows every later zone — this is what the mask
     * still defends.
     */
    public function testAForgedOpeningSentinelSwallowsEveryLaterZoneWhileARealMarkerDoesNot(): void
    {
        $mark = new Mark();

        $marked = $mark->wrap('tab1', 'A') . ImageOverlay::marker(0) . $mark->wrap('tab2', 'B');
        $scanner = Scanner::new();
        $scanner->scan($marked, 80);
        $this->assertSame(['tab1', 'tab2'], array_keys($scanner->all()));

        $forged = $mark->wrap('tab1', 'A') . "\u{E000}" . $mark->wrap('tab2', 'B');
        $scanner = Scanner::new();
        $scanner->scan($forged, 80);
        $this->assertSame(['tab1'], array_keys($scanner->all()));
    }

    /**
     * Renderer's root scan must survive forged Private-Use bytes and keep
     * painting real markers. Driven through the real
     * {@see \SugarCraft\Crush\Renderer} entry point rather than the private
     * mask, so this fails if the mask is ever dropped OR merely moved off the
     * scan path.
     */
    public function testRendererKeepsEveryZoneWhenTheFrameCarriesPrivateUseBytes(): void
    {
        $mark = new Mark();
        $frame = $mark->wrap('tab1', 'A') . "\u{E000}" . ImageOverlay::marker(3) . $mark->wrap('tab2', 'B');

        $scan = new \ReflectionMethod(\SugarCraft\Crush\Renderer::class, 'scanRoot');
        $scan->setAccessible(true);
        $scan->invoke(null, $frame, 80);

        $scanner = new \ReflectionMethod(\SugarCraft\Crush\Renderer::class, 'scanner');
        $scanner->setAccessible(true);

        $this->assertSame(['tab1', 'tab2'], array_keys($scanner->invoke(null)->all()));
    }

    /**
     * The mask must not shift columns: a Private-Use cell is one width-1 cell
     * and is replaced by one space, or every zone's hit box after it would be
     * off by one and clicks would land on the wrong row. Pinned for both the
     * forged sentinel byte and a live image marker.
     */
    public function testMaskingPreservesColumnArithmetic(): void
    {
        $mask = new \ReflectionMethod(\SugarCraft\Crush\Renderer::class, 'maskImageMarkers');
        $mask->setAccessible(true);

        $frame = "ab\u{E000}cd" . ImageOverlay::marker(0);
        $masked = $mask->invoke(null, $frame);

        $this->assertSame('ab cd ', $masked);
        $this->assertSame(mb_strlen($frame), mb_strlen($masked));
    }
}
