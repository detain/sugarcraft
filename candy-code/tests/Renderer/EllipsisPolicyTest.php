<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Context\Pruning\CompressionBlock;
use SugarCraft\Code\Context\Pruning\PruneAuthor;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Theme;
use SugarCraft\Image\ImageLayer;
use SugarCraft\Image\Mosaic;
use SugarCraft\Code\Media\PreviewSlots;

/**
 * The ellipsis policy (W2-D): a transcript row that was cut says so.
 *
 * The file's markerless `Width::truncate($x, $room)` chops at the compressed
 * section row and the live image preview caption were fixed to route through
 * the shared {@see Renderer::clipWithEllipsis()} clip — the same one session
 * tabs and the key-help rows already used — so a cut row ends in `…` instead
 * of stopping mid-word. Rows whose docblock states a reason to stay a bare
 * chop (the zone-sentinel status bar, the compaction boundary) are untouched,
 * and the default goldens byte-match: no captured frame overflowed these two
 * sites.
 *
 * Each site gets a width-invariant sweep plus the two behaviour polarities:
 * overflow ends in the marker, and a roomy render loses nothing to it.
 */
final class EllipsisPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        PreviewSlots::clearAll();
    }

    protected function tearDown(): void
    {
        PreviewSlots::clearAll();

        parent::tearDown();
    }

    // ---- the compressed section row ---------------------------------------

    public function testTheCompressedSectionRowStaysIntactWhenThePaneIsRoomy(): void
    {
        $row = self::compressedRow(200);

        self::assertStringStartsWith('▣ ', $row);
        self::assertStringNotContainsString('…', $row);
        self::assertStringContainsString('Authentication middleware exploration', $row);
    }

    public function testTheCompressedSectionRowAnnouncesItsCut(): void
    {
        $row = self::compressedRow(24);

        self::assertStringEndsWith('…', $row);
        self::assertLessThanOrEqual(24, Width::of($row));
        self::assertStringStartsWith('▣ ', $row);
    }

    public function testTheCompressedSectionRowFitsEveryPaneWidth(): void
    {
        foreach ([1, 2, 5, 13, 24, 40, 60, 80, 120, 200] as $width) {
            $row = self::compressedRow($width);
            self::assertLessThanOrEqual(max(1, $width), Width::of($row), "width {$width}: {$row}");
        }
    }

    /** One frame's compressed-section row, styling stripped. */
    private static function compressedRow(int $width): string
    {
        $block = new CompressionBlock(
            id: 3,
            keepFromToolCallId: 'call_1',
            summary: 'summarised range',
            compressedTokens: 41000,
            summaryTokens: 2400,
            by: PruneAuthor::Model,
            topic: 'Authentication middleware exploration across the payments gateway module',
        );

        $render = new \ReflectionMethod(Renderer::class, 'compressedSectionRow');

        return (string) preg_replace('/(?:\e\[[0-9;]*m)/', '', (string) $render->invoke(null, $block, Theme::default(), $width));
    }

    // ---- the live image preview caption ------------------------------------

    public function testThePreviewCaptionStaysIntactWhenThePaneIsRoomy(): void
    {
        PreviewSlots::set('call_prev', null, 0.42, 8.0);

        $block = self::previewCaptionBlock(40);

        self::assertStringContainsString('42%', $block);
        self::assertStringContainsString('eta 8s', $block);
        self::assertStringNotContainsString('…', $block);
    }

    public function testThePreviewCaptionAnnouncesItsCut(): void
    {
        PreviewSlots::set('call_prev', null, 0.42, 8.0);

        $block = self::previewCaptionBlock(8);

        self::assertStringEndsWith('…', $block);
        self::assertLessThanOrEqual(8, Width::of($block));
    }

    public function testThePreviewCaptionFitsEveryPaneWidth(): void
    {
        PreviewSlots::set('call_prev', null, 0.42, 8.0);

        foreach ([1, 2, 5, 8, 14, 24, 40] as $width) {
            $block = self::previewCaptionBlock($width);
            self::assertLessThanOrEqual($width, Width::of($block), "width {$width}: {$block}");
        }
    }

    /** The preview block for the armed slot, styling stripped. */
    private static function previewCaptionBlock(int $width): string
    {
        $render = new \ReflectionMethod(Renderer::class, 'previewLines');

        return rtrim((string) preg_replace('/(?:\e\[[0-9;]*m)/', '', (string) $render->invoke(
            null,
            'call_prev',
            Theme::default(),
            $width,
            new ImageLayer(),
            Mosaic::sixel(),
            6,
        )), "\n");
    }
}
