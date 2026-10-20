<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Image\Capability;
use SugarCraft\Image\DiskCache;
use SugarCraft\Image\Dither;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Renderer\ChafaRenderer;
use SugarCraft\Image\Renderer\ConfigFingerprintProvider;
use SugarCraft\Image\Renderer\HalfBlockRenderer;
use SugarCraft\Image\Renderer\Renderer;
use SugarCraft\Image\Renderer\SixelRenderer;
use SugarCraft\Image\TmuxPassthroughDecorator;

/**
 * D-04: the poster disk-cache key must cover everything that changes the
 * encoded bytes — including renderer construction config that lives BELOW
 * the protocol name (Sixel dither/maxColors/cell geometry, Chafa CLI
 * options and format) — while renderers with no byte-varying config keep
 * answering '' and their keys keep their exact pre-D-04 segment shape.
 */
final class DiskCacheKeyTest extends TestCase
{
    private const URL = 'https://example.test/key.png';

    public function testSixelFingerprintCoversEveryByteVaryingConfig(): void
    {
        $this->assertSame(
            'dither=floyd-steinberg|maxColors=256|cellWidth=10|cellHeight=20',
            (new SixelRenderer())->configFingerprint(),
            'default sixel fingerprint is a published cache-key segment',
        );

        $this->assertSame(
            'dither=stucki|maxColors=16|cellWidth=8|cellHeight=12',
            (new SixelRenderer(Dither::Stucki, 16, 8, 12))->configFingerprint(),
        );
    }

    public function testChafaFingerprintCoversOptionsAndFormat(): void
    {
        $this->assertSame(
            'opts=--colors=256|format=',
            (new ChafaRenderer(['--colors=256']))->configFingerprint(),
        );
        $this->assertSame(
            'opts=--colors=16,--transparent|format=sixel',
            (new ChafaRenderer(['--colors=16', '--transparent'], 'sixel'))->configFingerprint(),
        );
    }

    public function testSixelDitherConfigsGetDistinctPosterKeys(): void
    {
        $fs = $this->posterKey(Mosaic::sixel(Dither::FloydSteinberg), 8, 4);
        $stucki = $this->posterKey(Mosaic::sixel(Dither::Stucki), 8, 4);

        $this->assertNotSame($fs, $stucki, 'FS and Stucki sixel posters must never cross-serve');

        // Each key is exactly the documented segment shape, version included.
        $this->assertSame(
            DiskCache::key(self::URL, 8, 4, 'sixel|Fill|dither=floyd-steinberg|maxColors=256|cellWidth=10|cellHeight=20'),
            $fs,
        );
        $this->assertSame(
            DiskCache::key(self::URL, 8, 4, 'sixel|Fill|dither=stucki|maxColors=256|cellWidth=10|cellHeight=20'),
            $stucki,
        );
    }

    public function testSixelCellGeometryAndColorBudgetRideTheKey(): void
    {
        $base = $this->posterKey($this->mosaic(new SixelRenderer()), 8, 4);
        $budget = $this->posterKey($this->mosaic(new SixelRenderer(Dither::FloydSteinberg, 16)), 8, 4);
        $geometry = $this->posterKey($this->mosaic(new SixelRenderer(Dither::FloydSteinberg, 256, 8, 16)), 8, 4);

        $this->assertNotSame($base, $budget);
        $this->assertNotSame($base, $geometry);
        $this->assertNotSame($budget, $geometry);
    }

    public function testChafaOptionConfigsGetDistinctPosterKeys(): void
    {
        $wide = $this->posterKey($this->mosaic(new ChafaRenderer(['--colors=256'])), 8, 4);
        $narrow = $this->posterKey($this->mosaic(new ChafaRenderer(['--colors=16'])), 8, 4);
        $formatted = $this->posterKey($this->mosaic(new ChafaRenderer(['--colors=256'], 'sixel')), 8, 4);

        $this->assertNotSame($wide, $narrow, 'chafa --colors arms must never cross-serve');
        $this->assertNotSame($wide, $formatted);

        $this->assertSame(
            DiskCache::key(self::URL, 8, 4, 'chafa|Fill|opts=--colors=256|format='),
            $wide,
        );
    }

    public function testNonFingerprintingProtocolsKeepTheirPreD04KeySegmentByteIdentical(): void
    {
        $half = new HalfBlockRenderer();
        $this->assertFalse(
            $half instanceof ConfigFingerprintProvider,
            'half-block encodes no config that varies bytes beyond name() — it must stay fingerprint-free',
        );

        // The key must equal the exact pre-D-04 shape: no trailing '|' segment.
        $this->assertSame(
            DiskCache::key(self::URL, 8, 4, 'halfblock|Fill'),
            $this->posterKey($this->mosaic($half), 8, 4),
        );
    }

    public function testTmuxDecorationRidesTheInnerFingerprint(): void
    {
        $decoratedStucki = $this->mosaic(new TmuxPassthroughDecorator(new SixelRenderer(Dither::Stucki)));
        $decoratedFs = $this->mosaic(new TmuxPassthroughDecorator(new SixelRenderer(Dither::FloydSteinberg)));

        $this->assertNotSame(
            $this->posterKey($decoratedStucki, 8, 4),
            $this->posterKey($decoratedFs, 8, 4),
            'the envelope re-wraps identical bytes; the INNER config must still discriminate',
        );

        // Without the decorator unwrap the fingerprint would answer '' and
        // the segment would stop at the scale name.
        $this->assertSame(
            DiskCache::key(self::URL, 8, 4, 'tmux(sixel)|Fill|dither=stucki|maxColors=256|cellWidth=10|cellHeight=20'),
            $this->posterKey($decoratedStucki, 8, 4),
        );
    }

    public function testFormatVersionRetiresPreCanonicalSixelBlobs(): void
    {
        $version = (new ReflectionClass(DiskCache::class))->getConstant('FORMAT_VERSION');

        $this->assertSame(
            8,
            $version,
            'v8 retires v7 sixel blobs (broken Stucki kernel, first-wins memo, fingerprint-less key)',
        );
    }

    /**
     * Derive the auto-height sentinel from Mosaic itself rather than hardcoding it.
     */
    public function testNullHeightUsesTheAutoSentinelInEveryArm(): void
    {
        $sentinel = (new ReflectionClass(Mosaic::class))->getConstant('AUTO_HEIGHT');
        $this->assertIsInt($sentinel);

        $fs = $this->posterKey(Mosaic::sixel(Dither::FloydSteinberg), 8, null);
        $stucki = $this->posterKey(Mosaic::sixel(Dither::Stucki), 8, null);

        $this->assertSame(
            DiskCache::key(self::URL, 8, $sentinel, 'sixel|Fill|dither=stucki|maxColors=256|cellWidth=10|cellHeight=20'),
            $stucki,
        );
        $this->assertNotSame($fs, $stucki);
    }

    private function mosaic(Renderer $renderer): Mosaic
    {
        return new Mosaic($renderer, Capability::universal(), null, null, null);
    }

    private function posterKey(Mosaic $mosaic, int $cellW, ?int $cellH): string
    {
        $method = new ReflectionMethod(Mosaic::class, 'posterCacheKey');
        $method->setAccessible(true);

        return $method->invoke($mosaic, self::URL, $cellW, $cellH);
    }
}
