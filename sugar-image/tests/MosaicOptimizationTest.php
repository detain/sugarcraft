<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Detect;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Optimization;
use SugarCraft\Image\Renderer\AcceptsOptimization;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * A-02: Mosaic::withOptimization matrix.
 *
 * The law pinned here, in order of importance:
 *  1. None (and the documentary ReuseAttributes) is BYTE-IDENTICAL to the
 *     bare mosaic — the default-OFF proof riding the same frozen shapes as
 *     the dither digest family.
 *  2. RepeatCells compresses runs of identical cells into ESC[#b only while
 *     Detect::supportsRepeat() says yes, and expanding every REP back to
 *     literals must reproduce the None bytes exactly (the optimization is a
 *     transport choice, never a content choice).
 *  3. Sub-threshold rows stay literal under RepeatCells too (gate honesty).
 */
final class MosaicOptimizationTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/fixtures/300x300_green.png';

    protected function setUp(): void
    {
        Detect::clearSupportsRepeatOverride();
    }

    protected function tearDown(): void
    {
        Detect::clearSupportsRepeatOverride();
        parent::tearDown();
    }

    public function testNoneAndReuseAttributesAreByteIdenticalToBare(): void
    {
        $image = ImageSource::fromFile(self::FIXTURE);
        foreach ([Mosaic::halfBlock(), Mosaic::quarterBlock(), Mosaic::ascii()] as $mosaic) {
            $bare = $mosaic->render($image, 30);
            foreach ([Optimization::None, Optimization::ReuseAttributes] as $level) {
                self::assertSame(
                    $bare,
                    $mosaic->withOptimization($level)->render($image, 30),
                    'the level-' . $level->name . ' arm must never move a byte',
                );
            }
        }
    }

    public function testRepeatCellsCompressesUniformRowsAndRoundTrips(): void
    {
        $image = ImageSource::fromFile(self::FIXTURE);
        $mosaic = Mosaic::quarterBlock();
        $plain = $mosaic->render($image, 40);

        Detect::setSupportsRepeatOverride(true);
        $optimized = $mosaic->withOptimization(Optimization::RepeatCells)->render($image, 40);

        self::assertNotSame($plain, $optimized, 'a uniform 40-cell row must compress');
        self::assertMatchesRegularExpression('/\x1b\[\d+b/', $optimized);
        self::assertLessThan(strlen($plain), strlen($optimized), 'the gate only ever picks the smaller frame');
        self::assertSame(
            $plain,
            self::expandReps($optimized),
            'literal expansion of the optimized stream must equal the None stream',
        );
    }

    public function testCapabilityRefusalKeepsEveryRowLiteral(): void
    {
        $image = ImageSource::fromFile(self::FIXTURE);
        $plain = Mosaic::quarterBlock()->render($image, 40);

        Detect::setSupportsRepeatOverride(false);
        $optimized = Mosaic::quarterBlock()
            ->withOptimization(Optimization::RepeatCells)
            ->render($image, 40);

        self::assertSame($plain, $optimized, 'a terminal that cannot REP must never be sent CSI#b');
    }

    /**
     * A solid red 8x4 fixture renders one row of identical cells: with the
     * level on AND 16 repeats behind the gate, the row is one glyph plus
     * one escape.
     */
    public function testWinningRowShape(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        Detect::setSupportsRepeatOverride(true);

        $row = explode("\n", Mosaic::halfBlock()
            ->withOptimization(Optimization::RepeatCells)
            ->render($image, 8))[0];

        self::assertMatchesRegularExpression('/^\x1b\[38;2;\d+;\d+;\d+m\x1b\[48;2;\d+;\d+;\d+m.\x1b\[7b\x1b\[39;49m$/u', $row, '8 uniform cells: pen once, glyph once, REP 7, scoped fg/bg restore');
    }

    public function testImmutabilityAndAccessors(): void
    {
        $mosaic = Mosaic::quarterBlock();
        $optimized = $mosaic->withOptimization(Optimization::RepeatCells, Optimization::ReuseAttributes);

        self::assertNotSame($mosaic, $optimized);
        self::assertSame(0, $mosaic->optimizations(), 'the receiver is untouched');
        self::assertSame(Optimization::all(), $optimized->optimizations());

        $renderer = $optimized->renderer();
        self::assertInstanceOf(AcceptsOptimization::class, $renderer);
        self::assertSame(Optimization::all(), $renderer->optimizations());
    }

    public function testPixelBackendsCarryTheMaskWithoutRendererChange(): void
    {
        $mosaic = Mosaic::sixel();
        $optimized = $mosaic->withOptimization(Optimization::RepeatCells);

        self::assertInstanceOf(SixelRenderer::class, $optimized->renderer());
        self::assertNotInstanceOf(AcceptsOptimization::class, $optimized->renderer());
        self::assertSame(Optimization::RepeatCells->value, $optimized->optimizations(), 'the key-discriminating mask still rides for pixel backends');

        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        self::assertSame(
            $mosaic->render($image, 8),
            $optimized->render($image, 8),
            'sixel bytes are insensitive to the cell ladder',
        );
    }

    private static function expandReps(string $bytes): string
    {
        return preg_replace_callback(
            '/(.)\x1b\[(\d+)b/su',
            static fn (array $m): string => $m[1] . str_repeat($m[1], (int) $m[2]),
            $bytes,
        ) ?? $bytes;
    }
}
