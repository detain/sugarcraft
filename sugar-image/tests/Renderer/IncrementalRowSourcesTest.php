<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\IncrementalRenderer;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\Renderer\SixelRows;
use SugarCraft\Image\Support\PushToPull;

/**
 * Row-source pins for the D-05 incremental contract: every generator's
 * concatenation must equal its renderer's one-shot bytes, and the chunk
 * granularity must be what the pacing layer documents.
 */
final class IncrementalRowSourcesTest extends TestCase
{
    /**
     * D-05: half-block yields one display row per unit and reproduces render()
     * byte-for-byte when concatenated.
     */
    public function testHalfBlockRowsConcatToRender(): void
    {
        $renderer = new HalfBlockRenderer();
        $image    = ImageSource::fromFile(__DIR__ . '/../fixtures/checkerboard.png');

        $rows = iterator_to_array($renderer->renderRows($image, 16, 8), false);

        $this->assertCount(8, $rows, 'one chunk per cell row');
        $this->assertSame($renderer->render($image, 16, 8), implode('', $rows));
        $this->assertStringStartsWith("\n", $rows[1], 'rows after the first carry their joining newline');
    }

    /**
     * D-05: the transparent-half shapes (holes both ways) survive the row split
     * unchanged — the OutputOptimizer pen is row-stateful across yields.
     */
    public function testHalfBlockRowsOverTransparentFixture(): void
    {
        $renderer = new HalfBlockRenderer();
        $image    = ImageSource::fromFile(__DIR__ . '/../fixtures/2x2_transparent_halfblock.png');

        $this->assertSame(
            $renderer->render($image, 4, 2),
            implode('', iterator_to_array($renderer->renderRows($image, 4, 2), false)),
        );
    }

    /**
     * D-05: kitty yields the begin frame, then exactly one frame per raw-byte
     * slice (ceil(payload/2967) — the PNG fixture rides through ensurePng
     * untouched), and concatenates to render().
     */
    public function testKittyRowsConcatToRenderAndChunkPerFrame(): void
    {
        $renderer = new KittyRenderer();
        $image    = ImageSource::fromFile(__DIR__ . '/../fixtures/500x400_noise.png');
        $payload  = file_get_contents(__DIR__ . '/../fixtures/500x400_noise.png');

        $rows = iterator_to_array($renderer->renderRows($image, 20, 20), false);

        $expectedChunks = (int) ceil(strlen($payload) / \SugarCraft\Image\KittyOptions::DEFAULT_CHUNK_BYTES);
        $this->assertGreaterThan(1, $expectedChunks, 'fixture must exercise multi-chunk transmission');
        $this->assertCount(1 + $expectedChunks, $rows, 'begin frame + one frame per slice');
        $this->assertSame($renderer->render($image, 20, 20), implode('', $rows));
    }

    /**
     * D-05: sixel rows are header + one chunk per 6-px band + terminator; an
     * 8×4-cell box is a 80×80-px canvas → 14 bands.
     */
    public function testSixelRowsGranularityAndConcat(): void
    {
        $renderer = new SixelRenderer();
        $rows     = new SixelRows($renderer);
        $image    = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');

        $chunks = iterator_to_array($rows->renderRows($image, 8, 4), false);

        $this->assertCount(16, $chunks, 'head + ceil(80/6)=14 bands + terminator');
        $this->assertSame($renderer->render($image, 8, 4), implode('', $chunks));
        $this->assertSame($renderer->render($image, 8, 4), $rows->renderer()->render($image, 8, 4));
    }

    /**
     * D-05: the sixel pump advances one chunk at a time — each next() parks
     * the wrapped encodeBandStream at exactly one more band (laziness itself
     * is pinned on PushToPull, which this class drives).
     */
    public function testSixelRowsAdvanceOneChunkAtATime(): void
    {
        $rows  = new SixelRows(new SixelRenderer());
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');

        $generator = $rows->renderRows($image, 8, 4);

        $this->assertTrue($generator->valid());
        $this->assertSame(0, $generator->key());
        $generator->next();
        $this->assertSame(1, $generator->key(), 'exactly one chunk per advance');
        $generator->current();
        $this->assertSame(1, $generator->key());
    }

    /**
     * D-05: abandoning a sixel pump mid-stream is safe — the suspended fiber
     * discards, GD releases by refcount, no warnings escape.
     */
    public function testAbandoningSixelRowsMidStreamIsSafe(): void
    {
        $rows  = new SixelRows(new SixelRenderer());
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');

        $generator = $rows->renderRows($image, 8, 4);
        $generator->current();
        $generator->next();
        $generator->current();
        unset($generator);
        gc_collect_cycles();

        $this->assertTrue(true, 'reached teardown with a half-consumed stream');
    }

    /**
     * D-05: PushToPull bridges a counting push producer 1:1, in order, and
     * refuses non-string suspensions loudly rather than yielding garbage.
     */
    public function testPushToPullBridgesInOrderAndLazily(): void
    {
        $writes = 0;
        $produce = function (callable $write) use (&$writes): void {
            foreach (['a', 'b', 'c'] as $chunk) {
                ++$writes;
                $write($chunk);
            }
        };

        $rows = PushToPull::chunks($produce);
        $this->assertSame(0, $writes, 'producer idle before the pump');

        $out = '';
        foreach ($rows as $row) {
            $out .= $row;
            if ($out === 'ab') {
                $this->assertSame(2, $writes, 'producer sits parked between advances');
            }
        }
        $this->assertSame('abc', $out);
        $this->assertSame(3, $writes);
    }

    /**
     * D-05: every shipped row source really implements the narrow contract.
     */
    public function testRowSourcesAdvertiseTheCapability(): void
    {
        foreach ([new HalfBlockRenderer(), new KittyRenderer(), new SixelRows(new SixelRenderer())] as $source) {
            $this->assertInstanceOf(IncrementalRenderer::class, $source);
        }
    }
}
