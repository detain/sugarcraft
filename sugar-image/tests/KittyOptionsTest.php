<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\KittyOptions;
use SugarCraft\Image\Renderer\KittyRenderer;

final class KittyOptionsTest extends TestCase
{
    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KittyRenderer();
    }

    public function testTransmitIncludesImageIdInBeginSequence(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::transmit(42);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Begin must carry i=42
        $this->assertStringContainsString('i=42', $out);
        // Place action must NOT be present
        $this->assertStringNotContainsString('a=p', $out);
    }

    public function testTransmitWithZIndexIncludesZInBeginSequence(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withZIndex(5);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringContainsString('z=5', $out);
    }

    public function testPlaceActionEmitsPlaceSequence(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::place(imageId: 7, x: 2, y: 1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Should contain a=p (place action), i=7 and the cell destination X=2 Y=1
        $this->assertStringContainsString('a=p', $out);
        $this->assertStringContainsString('i=7', $out);
        $this->assertStringContainsString('X=2', $out);
        $this->assertStringContainsString('Y=1', $out);
        // Lowercase x=/y= are the spec's SOURCE-RECTANGLE pixel offsets: a
        // place frame that wrote them asked the terminal to crop, not to move.
        $this->assertStringNotContainsString('x=2', $out);
        $this->assertStringNotContainsString('y=1', $out);
        // Should NOT have chunk data (no base64 data in place mode)
        $this->assertStringNotContainsString(',PHN2', $out); // no base64 PNG header
    }

    public function testPlaceWithoutOffsetOmitsXY(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::place(3);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // a=p and i=3 must be present, but x= and y= should not appear
        $this->assertStringContainsString('a=p', $out);
        $this->assertStringContainsString('i=3', $out);
        $this->assertStringNotContainsString('x=', $out);
        $this->assertStringNotContainsString('y=', $out);
    }

    public function testWithCompressionSetsCompression(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(1); // zlib

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // o=z is the spec's zlib transmission-compression key; f stays 100.
        $this->assertStringContainsString('o=z', $out);
        $this->assertStringContainsString('f=100', $out);
    }

    public function testKittyOptionsToArraySerializesZeroImageIdButOmitsOtherZeroDefaults(): void
    {
        $opts = KittyOptions::transmit(0);
        $arr  = $opts->toArray();

        // M2 cure: id 0 is a real addressable image, serialized as-is.
        $this->assertSame(0, $arr['i']);
        // z=0 should be omitted
        $this->assertNull($arr['z']);
        // no compression requested → o omitted, and f never carries
        // compression (the data format belongs to the renderer)
        $this->assertNull($arr['o']);
        $this->assertArrayNotHasKey('f', $arr);
    }

    public function testZIndexCanBeNegative(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withZIndex(-3);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringContainsString('z=-3', $out);
    }

    public function testWithCellDimensionsEmitsSAndVParams(): void
    {
        // cellWidth/cellHeight had no setter, so s=/v= were never emitted.
        // withCellDimensions() is the seam that makes them settable. Despite
        // the name these are the source display extents in PIXELS (the spec's
        // `s=`/`v=`); the cell grid is `c=`/`r=`, which the renderer fills from
        // its own geometry — see testWithSourceDimensionsIsTheUnitHonestAlias.
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCellDimensions(120, 48);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringContainsString('s=120', $out);
        $this->assertStringContainsString('v=48', $out);
    }

    public function testWithCellDimensionsPopulatesToArray(): void
    {
        $arr = KittyOptions::transmit(1)->withCellDimensions(64, 32)->toArray();

        $this->assertSame(64, $arr['s']);
        $this->assertSame(32, $arr['v']);
    }

    public function testCellDimensionsOmittedByDefault(): void
    {
        // Without the setter the fields stay 0, so s=/v= must NOT appear.
        $arr = KittyOptions::transmit(1)->toArray();

        $this->assertNull($arr['s']);
        $this->assertNull($arr['v']);
    }

    public function testWithCellDimensionsRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        KittyOptions::transmit(1)->withCellDimensions(-1, 10);
    }

    /**
     * `s=`/`v=` are pixel extents, so the method that sets them is named for
     * what it actually does. The alias exists so callers can say “source
     * dimensions” without the historical name lying in the call site; both
     * spellings must produce the same frame.
     */
    public function testWithSourceDimensionsIsTheUnitHonestAlias(): void
    {
        $viaLegacy = KittyOptions::transmit(1)->withCellDimensions(120, 48);
        $viaAlias  = KittyOptions::transmit(1)->withSourceDimensions(120, 48);

        $this->assertSame($viaLegacy->toArray(), $viaAlias->toArray());

        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $out   = $this->renderer->renderWithOptions($image, 8, 4, $viaAlias);

        $this->assertStringContainsString('s=120', $out);
        $this->assertStringContainsString('v=48', $out);
        // The cell grid the renderer chose stays separate from the pixel box.
        $this->assertStringContainsString('c=8,r=4', $out);
    }

    /**
     * A place frame's destination is a cell coordinate and must travel on the
     * spec's `X=`/`Y=`; the lowercase pair means “crop the source” instead.
     */
    public function testAPlaceFrameCarriesTheCellDestinationOnUppercaseKeys(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $out   = $this->renderer->renderWithOptions(
            $image,
            8,
            4,
            KittyOptions::place(7, x: 3, y: 9),
        );

        $this->assertStringContainsString('a=p', $out);
        $this->assertStringContainsString('i=7', $out);
        $this->assertStringContainsString('X=3', $out);
        $this->assertStringContainsString('Y=9', $out);
        $this->assertStringNotContainsString('x=3', $out);
        $this->assertStringNotContainsString('y=9', $out);
    }

    public function testWithChunkBytesFloorsToBase64Alignment(): void
    {
        // An unaligned override is floored to a multiple of 3 so no
        // intermediate chunk ever carries base64 padding.
        $opts = KittyOptions::transmit(1)->withChunkBytes(500);

        $this->assertSame(498, $opts->chunkBytes());
    }

    public function testWithChunkBytesNullRestoresTheDefault(): void
    {
        // Null means "use the framed default", not "0 bytes".
        $opts = KittyOptions::transmit(1)->withChunkBytes(63)->withChunkBytes(null);

        $this->assertNull($opts->chunkBytes());
    }

    public function testWithChunkBytesRejectsUndersizedOverride(): void
    {
        // One base64 group is the floor; 2 bytes cannot align.
        $this->expectException(\InvalidArgumentException::class);

        KittyOptions::transmit(1)->withChunkBytes(2);
    }

    public function testWithChunkBytesRejectsOversizedOverride(): void
    {
        // Past MAX_CHUNK_BYTES the data-chunk envelope itself would
        // push the frame beyond the 4 KiB spec cap.
        $this->expectException(\InvalidArgumentException::class);

        KittyOptions::transmit(1)->withChunkBytes(KittyOptions::MAX_CHUNK_BYTES + 3);
    }
}
