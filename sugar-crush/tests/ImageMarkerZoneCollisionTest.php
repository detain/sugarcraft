<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Sentinel;

/**
 * Regression guard for a defect that existed in NEITHER feature branch alone
 * and only appeared where the two Wave 2 tracks met: tool-result image
 * rendering (crush_feat.md §9 E3) and the mouse/zone chain (§8).
 *
 * candy-core's `ImageOverlay::MARKER_BASE` is U+E000 and an image marker cell
 * is `MARKER_BASE + id`, while candy-mouse's {@see Sentinel::OPEN} is
 * literally U+E000. Ids are handed out from 0, so the FIRST image in a frame
 * emits a byte-identical copy of an opening zone sentinel. The scanner then
 * treats the rest of the frame as one unterminated zone and silently drops
 * every zone after it — meaning session tabs, panes, tool rows and the status
 * bar all stopped responding to clicks whenever a screenshot was on screen.
 *
 * Renderer masks the private-use block out of the copy the SCANNER reads
 * (never the copy sent to the terminal, which still needs its real markers
 * for Program to resolve into paints).
 */
final class ImageMarkerZoneCollisionTest extends TestCase
{
    /**
     * The collision itself, stated as a property of the two libraries rather
     * than of our code: if this ever stops being true the mask is dead weight
     * and this whole guard should be revisited.
     */
    public function testAnImageMarkerIsByteIdenticalToAnOpeningZoneSentinel(): void
    {
        $this->assertSame(Sentinel::OPEN, "\u{E000}");
    }

    /** The raw frame really does lose every zone after an image marker. */
    public function testAnUnmaskedImageMarkerSwallowsEveryLaterZone(): void
    {
        $mark = new Mark();
        $frame = $mark->wrap('tab1', 'A') . "\u{E000}" . $mark->wrap('tab2', 'B');

        $scanner = Scanner::new();
        $scanner->scan($frame, 80);

        $this->assertSame(['tab1'], array_keys($scanner->all()));
    }

    /**
     * Renderer's root scan must survive it. Driven through the real
     * {@see \SugarCraft\Crush\Renderer} entry point rather than the private
     * mask, so this fails if the mask is ever dropped OR merely moved off the
     * scan path.
     */
    public function testRendererKeepsEveryZoneWhenTheFrameCarriesImageMarkers(): void
    {
        $mark = new Mark();
        $frame = $mark->wrap('tab1', 'A') . "\u{E000}" . $mark->wrap('tab2', 'B');

        $scan = new \ReflectionMethod(\SugarCraft\Crush\Renderer::class, 'scanRoot');
        $scan->setAccessible(true);
        $scan->invoke(null, $frame, 80);

        $scanner = new \ReflectionMethod(\SugarCraft\Crush\Renderer::class, 'scanner');
        $scanner->setAccessible(true);

        $this->assertSame(['tab1', 'tab2'], array_keys($scanner->invoke(null)->all()));
    }

    /**
     * The mask must not shift columns: a marker is one width-1 cell and is
     * replaced by one space, or every zone's hit box after it would be off by
     * one and clicks would land on the wrong row.
     */
    public function testMaskingPreservesColumnArithmetic(): void
    {
        $mask = new \ReflectionMethod(\SugarCraft\Crush\Renderer::class, 'maskImageMarkers');
        $mask->setAccessible(true);

        $frame = "ab\u{E000}cd";
        $masked = $mask->invoke(null, $frame);

        $this->assertSame('ab cd', $masked);
        $this->assertSame(mb_strlen($frame), mb_strlen($masked));
    }
}
