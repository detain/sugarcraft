<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Preview;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Crush\Media\PreviewSlots;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\Mosaic;

/**
 * The running tool row's live-preview block (W2.4): the newest frame paints
 * under the `running:` line, replaced per frame, and the row without a slot is
 * BYTE-IDENTICAL to the row that never had one - no preview, no difference,
 * which is the degrade-honestly polarity the plan demands. Blob protocols
 * carry a marker block in the frame and the picture bytes out-of-band, the
 * sanctioned ImageLayer contract, never a base64 spill into the transcript.
 */
final class RunningRowPreviewTest extends TestCase
{
    /** A 2x2 PNG - tiny, but wide enough that a halfblock body exists. */
    private const SQUARE_PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWPkqjjBwMDAxMDAwMDAAAAO5AFOI5hpuQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        PreviewSlots::clearAll();
    }

    protected function tearDown(): void
    {
        PreviewSlots::clearAll();
    }

    public function testARowWithoutASlotIsByteIdenticalToBeforePreviewsExisted(): void
    {
        $plain = $this->paintRow(100, null, null, 0);

        PreviewSlots::clearAll();
        $afterClear = $this->paintRow(100, null, null, 0);

        $this->assertSame($plain, $afterClear);
        $this->assertStringContainsString('⠴', $plain, 'fixture: the running line itself still renders');
    }

    public function testACaptionOnlySlotAddsThePercentAndEtaLine(): void
    {
        $base = $this->paintRow(100, new ImageLayer(), Mosaic::fromModeString('halfblock'), 8);
        PreviewSlots::set('call-1', null, 0.423, 8.4);

        $with = $this->paintRow(100, new ImageLayer(), Mosaic::fromModeString('halfblock'), 8);

        $this->assertNotSame($base, $with);
        $this->assertStringStartsWith($base, $with, 'the preview block appends under an untouched running line');
        $this->assertStringContainsString('42%', $with);
        $this->assertStringContainsString('eta 8s', $with);
    }

    public function testAProgressOutOfRangeIsClampedIntoCaptionRange(): void
    {
        // Co-tenant honesty: /progress is shared UI state, a frame may carry
        // 1.7 or -0.2; the caption clamps at display, the slot keeps it raw.
        PreviewSlots::set('call-1', null, 1.7, null);
        $over = $this->paintRow(100, new ImageLayer(), Mosaic::fromModeString('halfblock'), 8);
        $this->assertStringContainsString('100%', $over);

        PreviewSlots::set('call-1', null, -0.2, null);
        $under = $this->paintRow(100, new ImageLayer(), Mosaic::fromModeString('halfblock'), 8);
        $this->assertStringContainsString('0%', $under);
    }

    public function testAnInlineFramePaintsIntoTheRowText(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }
        PreviewSlots::set('call-1', self::SQUARE_PNG_B64, 0.5, 4.0);

        $row = $this->paintRow(100, new ImageLayer(), Mosaic::fromModeString('halfblock'), 8);

        $this->assertStringContainsString('50%', $row);
        $this->assertGreaterThan(2, substr_count($row, "\n"), 'caption line plus painted block lines land under the running line');
    }

    public function testABlobFrameRidesTheImageLayerMarkerAndNeverSpillsBytes(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('candy-mosaic decodes images through ext-gd');
        }
        $mosaic = Mosaic::fromModeString('sixel');
        $this->assertNotNull($mosaic);
        $this->assertFalse($mosaic->isInline(), 'fixture: sixel is a blob protocol');
        PreviewSlots::set('call-1', self::SQUARE_PNG_B64, null, null);

        $images = new ImageLayer();
        $row = $this->paintRow(100, $images, $mosaic, 8);

        $this->assertCount(1, $images->placements(), 'the picture is carried out-of-band');
        $this->assertStringNotContainsString(base64_decode(self::SQUARE_PNG_B64, true), $row, 'no raw PNG bytes in the frame text');
    }

    public function testAFrameThatCannotBePaintedDegradesToThePlainRow(): void
    {
        $base = $this->paintRow(100, new ImageLayer(), Mosaic::fromModeString('halfblock'), 8);
        // A corrupt frame is refused by the painter; with no caption numbers
        // either, the row must not gain an empty line.
        PreviewSlots::set('call-1', 'bm90IGEgcG5nIGF0IGFsbA==', null, null);

        $with = $this->paintRow(100, new ImageLayer(), Mosaic::fromModeString('halfblock'), 8);

        $this->assertSame($base, $with, 'bad bytes cost the frame, nothing else');
    }

    public function testWithoutADisplayProtocolNothingIsPainted(): void
    {
        // No resolved protocol (mosaic null) or no image layer means the
        // whole preview block stands down: percent alone on a terminal that
        // can never show the frame would tease, so the row stays byte-identical
        // to a no-preview render - the degrade-honestly polarity.
        PreviewSlots::set('call-1', self::SQUARE_PNG_B64, 0.25, 12.0);

        $noMosaic = $this->paintRow(100, new ImageLayer(), null, 8);
        $this->assertStringNotContainsString('25%', $noMosaic);

        $noLayer = $this->paintRow(100, null, Mosaic::fromModeString('halfblock'), 8);
        $this->assertStringNotContainsString('25%', $noLayer);

        PreviewSlots::clearAll();
        $this->assertSame($this->paintRow(100, new ImageLayer(), null, 8), $noMosaic, 'the block vanishes with the slot, not before it');
    }

    public function testPreviewOnlyForTheCallThatOwnsTheSlot(): void
    {
        PreviewSlots::set('call-2', null, 0.9, 1.0);

        $row = $this->paintRow(100, new ImageLayer(), Mosaic::fromModeString('halfblock'), 8);

        $this->assertStringNotContainsString('90%', $row, 'another call in flight may not borrow this row');
    }

    private function paintRow(int $width, ?ImageLayer $images, ?Mosaic $mosaic, int $imageRows): string
    {
        $msg = Message::toolRunning(new ToolCall('GenerateImage', ['prompt' => 'a test square'], 'call-1'));
        $method = new ReflectionMethod(Renderer::class, 'renderPendingToolCall');
        $method->setAccessible(true);

        /** @var string */
        return $method->invoke(null, $msg, Theme::default(), [], $width, null, $images, $mosaic, $imageRows);
    }
}
