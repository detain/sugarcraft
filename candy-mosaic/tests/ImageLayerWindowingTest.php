<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\Renderer;

/**
 * Viewport windowing + digest index on ImageLayer — the policy the phlix
 * console client hand-rolled around the lib (PosterLoader), now first-class.
 *
 * @covers \SugarCraft\Mosaic\ImageLayer::digestFor
 * @covers \SugarCraft\Mosaic\ImageLayer::trackedDigests
 * @covers \SugarCraft\Mosaic\ImageLayer::imageIdForDigest
 * @covers \SugarCraft\Mosaic\ImageLayer::release
 * @covers \SugarCraft\Mosaic\ImageLayer::releaseAllExcept
 */
final class ImageLayerWindowingTest extends TestCase
{
    /**
     * Renderer double recording delete() calls with a deterministic payload.
     */
    private function fakeRenderer(): Renderer
    {
        return new class implements Renderer {
            public function render(ImageSource $image, int $width, ?int $height = null): string
            {
                return '';
            }

            public function name(): string
            {
                return 'fake';
            }

            public function supportsAlpha(): bool
            {
                return false;
            }

            public function isInline(): bool
            {
                return false;
            }

            public function delete(string $imageId): string
            {
                return "\x1b[FAKE;D{$imageId}\x07";
            }
        };
    }

    // ---- digest index ----------------------------------------------------

    public function testDigestForIsDeterministicAndSizeSensitive(): void
    {
        $a = ImageLayer::digestFor('BYTES', 8, 4);
        $b = ImageLayer::digestFor('BYTES', 8, 4);
        $c = ImageLayer::digestFor('BYTES', 8, 5);
        $d = ImageLayer::digestFor('OTHER', 8, 4);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c, 'same bytes at another size is a different allocation');
        $this->assertNotSame($a, $d);
    }

    public function testPlaceTrackedRegistersTheWindowDigest(): void
    {
        $layer = new ImageLayer();
        $placed = $layer->placeTracked('ONE', 4, 2);

        $digest = ImageLayer::digestFor('ONE', 4, 2);
        $this->assertSame([$digest], $layer->trackedDigests());
        $this->assertSame($placed->imageId, $layer->imageIdForDigest($digest));
        $this->assertNull($layer->imageIdForDigest('never-seen'));
    }

    public function testRePlacingSameContentAndSizeReusesDigestEntry(): void
    {
        $layer = new ImageLayer();
        $first  = $layer->placeTracked('ONE', 4, 2);
        $second = $layer->placeTracked('ONE', 4, 2);

        $this->assertSame($first->imageId, $second->imageId);
        $this->assertCount(1, $layer->trackedDigests());
    }

    public function testSameBytesAtDifferentSizesAreDistinctDigestsSharedByteId(): void
    {
        $layer = new ImageLayer();
        $small = $layer->placeTracked('ONE', 4, 2);
        $large = $layer->placeTracked('ONE', 8, 4);

        // Byte-content dedup gives both footprints the same id; the window
        // index keeps the two digests apart.
        $this->assertSame($small->imageId, $large->imageId);
        $this->assertCount(2, $layer->trackedDigests());
        $this->assertSame(
            $large->imageId,
            $layer->imageIdForDigest(ImageLayer::digestFor('ONE', 8, 4)),
        );
    }

    public function testExhaustedPlacementRegistersNoWindowDigest(): void
    {
        $layer = new ImageLayer();
        for ($i = 0; $i < \SugarCraft\Core\ImageOverlay::MAX_IMAGES; $i++) {
            $layer->placeTracked(pack('N', $i), 1, 1);
        }

        $before = count($layer->trackedDigests());
        $overflow = $layer->placeTracked('OVERFLOW', 2, 1);

        $this->assertNull($overflow->imageId);
        $this->assertCount($before, $layer->trackedDigests(), 'a blank block owns no window entry');
    }

    // ---- release -----------------------------------------------------------

    public function testReleaseUnregistersAndReturnsTheDeleteSequence(): void
    {
        $layer = new ImageLayer($this->fakeRenderer());
        $placed = $layer->placeTracked('ONE', 4, 1);

        $seq = $layer->release($placed->imageId);

        $this->assertSame("\x1b[FAKE;D{$placed->imageId}\x07", $seq);
        $this->assertCount(0, $layer->placements());
        $this->assertTrue($layer->isEmpty());
    }

    public function testReleaseOfUnknownIdIsANoOp(): void
    {
        $layer = new ImageLayer($this->fakeRenderer());

        $this->assertSame('', $layer->release(999));
        $this->assertTrue($layer->isEmpty());
    }

    public function testReleaseOnRendererlessLayerReturnsEmptySequence(): void
    {
        $layer = new ImageLayer();
        $placed = $layer->placeTracked('ONE', 4, 1);

        $this->assertSame('', $layer->release($placed->imageId));
    }

    public function testReleasedContentKeepsItsIdForDedup(): void
    {
        $layer = new ImageLayer();
        $first = $layer->placeTracked('ONE', 4, 1);
        $layer->release($first->imageId);

        // Content-addressed identity survives the release: the same bytes
        // re-enter under the same id, so stale markers from the scrollback
        // would paint correctly again.
        $again = $layer->placeTracked('ONE', 4, 1);
        $this->assertSame($first->imageId, $again->imageId);
        $this->assertSame($first->marker, $again->marker);
    }

    // ---- releaseAllExcept ----------------------------------------------------

    public function testReleaseAllExceptFreesTheWindowAndReportsSequences(): void
    {
        $layer = new ImageLayer($this->fakeRenderer());
        $a = $layer->placeTracked('A', 4, 1);
        $b = $layer->placeTracked('B', 4, 1);
        $c = $layer->placeTracked('C', 4, 1);

        // Scroll: only B remains visible.
        $released = $layer->releaseAllExcept([$b->imageId]);

        $this->assertSame(
            [
                $a->imageId => "\x1b[FAKE;D{$a->imageId}\x07",
                $c->imageId => "\x1b[FAKE;D{$c->imageId}\x07",
            ],
            $released,
        );
        $this->assertCount(1, $layer->placements());
        $this->assertArrayHasKey($b->imageId, $layer->placements());
    }

    public function testReleaseAllExceptWithEmptyKeepFreesEverything(): void
    {
        $layer = new ImageLayer($this->fakeRenderer());
        $layer->placeTracked('A', 4, 1);
        $layer->placeTracked('B', 4, 1);

        $released = $layer->releaseAllExcept([]);

        $this->assertSame([0, 1], array_keys($released));
        $this->assertTrue($layer->isEmpty());
    }

    public function testReleaseAllExceptIgnoresKeepIdsThatWereNeverPlaced(): void
    {
        $layer = new ImageLayer($this->fakeRenderer());
        $a = $layer->placeTracked('A', 4, 1);

        $released = $layer->releaseAllExcept([$a->imageId, 12345]);

        $this->assertSame([], $released);
        $this->assertCount(1, $layer->placements());
    }

    public function testReleaseAllExceptOnRendererlessLayerReportsEmptySequences(): void
    {
        $layer = new ImageLayer();
        $layer->placeTracked('A', 4, 1);

        $this->assertSame([0 => ''], $layer->releaseAllExcept([]));
    }

    public function testRendererAccessor(): void
    {
        $renderer = $this->fakeRenderer();

        $this->assertNull((new ImageLayer())->renderer());
        $this->assertSame($renderer, (new ImageLayer($renderer))->renderer());
    }
}
