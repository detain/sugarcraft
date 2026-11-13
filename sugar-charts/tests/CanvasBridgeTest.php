<?php

declare(strict_types=1);

/*

 * This file is part of the sugarcraft/sugar-charts package.
 *
 * (c) Joe Huss <detain@interserver.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * and NOTICE files that were distributed with this source code.
 */

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\LineChart\LineChart;
use SugarCraft\Charts\Support\CellGrid;
use SugarCraft\Style\Style;

/**
 * sugar-charts-007 (CH-04): withCanvas() is a typed render bridge — the
 * chart paints into the provided grid (mutating it) instead of using the
 * object as an inert braille-mode flag.
 */
final class CanvasBridgeTest extends TestCase
{
    /**
     * A recorder double: forwards to a real Canvas and logs every write,
     * proving the render path calls into the CALLER's grid.
     */
    public function testRenderPaintsTheProvidedGridAndRecordsEveryCellWrite(): void
    {
        $recorder = new class implements CellGrid {
            /** @var list<array{int, string}> */
            public array $writes = [];

            private Canvas $inner;

            public function __construct()
            {
                $this->inner = new Canvas(24, 6);
            }

            public function setCell(int $x, int $y, string $rune, ?Style $style = null): void
            {
                $this->writes[] = [$x, $rune];
                $this->inner->setCell($x, $y, $rune, $style);
            }

            public function setString(int $x, int $y, string $text, ?Style $style = null): void
            {
                foreach (preg_split("//u", $text, -1, PREG_SPLIT_NO_EMPTY) as $i => $cluster) {
                    $this->setCell($x + $i, $y, $cluster, $style);
                }
            }

            public function width(): int
            {
                return $this->inner->width();
            }

            public function height(): int
            {
                return $this->inner->height();
            }

            public function view(): string
            {
                return $this->inner->view();
            }
        };

        $out = LineChart::new([3.0, 1.0, 4.0, 1.0, 5.0], 24, 6)
            ->withCanvas($recorder)
            ->view();

        $this->assertNotEmpty($recorder->writes, 'the bridge received setCell calls');
        $this->assertSame($out, $recorder->view(), 'view() is the provided grid rendered');
    }

    public function testBridgeOutputEqualsInternalCanvasRenderByteForByte(): void
    {
        $data = [2.0, 7.0, 3.0, 9.0, 1.0, 6.0];
        $viaBridge = LineChart::new($data, 30, 8)
            ->withCanvas(new Canvas(30, 8))
            ->view();
        $internal = LineChart::new($data, 30, 8)->view();

        $this->assertSame($internal, $viaBridge, 'bridging must not change bytes for the same grid size');
    }

    public function testNullCanvasClearsTheBridgeBackToInternalRendering(): void
    {
        $plain = LineChart::new([1.0, 2.0], 20, 5);
        $bridged = $plain->withCanvas(new Canvas(20, 5));
        $this->assertNotNull($bridged->canvas);
        $this->assertSame($plain->view(), $bridged->view());

        $cleared = $bridged->withCanvas(null);
        $this->assertNull($cleared->canvas);
    }

    public function testBrailleModeComposesWithTheBridge(): void
    {
        $canvas = new Canvas(40, 8);
        $out = LineChart::new([1, 4, 2, 8, 6, 3, 7], 40, 8)
            ->withBraille(true)
            ->withCanvas($canvas)
            ->view();

        $this->assertMatchesRegularExpression('/[\x{2800}-\x{28FF}]/u', $out);
        $this->assertSame($out, $canvas->view(), 'the braille raster landed in the provided grid');
    }

    public function testCanvasBaseSurfaceImplementsTheContract(): void
    {
        $canvas = new Canvas(4, 2);
        $this->assertInstanceOf(CellGrid::class, $canvas);
        $this->assertSame(4, $canvas->width());
        $this->assertSame(2, $canvas->height());
        $canvas->setCell(0, 0, 'x');
        $this->assertStringContainsString('x', $canvas->view());
    }
}
