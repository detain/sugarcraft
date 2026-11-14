<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plot;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Plot\Chart\Donut;
use SugarCraft\Dashboard\Plot\Chart\DotMatrix;
use SugarCraft\Dashboard\Plot\Chart\GaugeWithDetail;
use SugarCraft\Dashboard\Plot\Chart\Partition;
use SugarCraft\Dashboard\Plot\Chart\PartitionSegment;
use SugarCraft\Dashboard\Plot\Chart\Progress;
use SugarCraft\Dashboard\Plot\Chart\WordCloud;
use SugarCraft\Dashboard\Plot\Graph\Graph;

/**
 * Legend/label data is attacker-adjacent (series names come from files,
 * APIs and user input) - no frame may paint a byte of it verbatim (finding
 * sugar-dashboard-191). These probes carry a screen-clear, an OSC title
 * steal and a visible marker through every chart entry point: the marker
 * survives (sanitized text is still text), the escape sequences never do.
 *
 * Cell budgeting rides along (same finding family): wide CJK glyphs cost
 * CELLS, not codepoints - rows must hold their allocation exactly.
 */
final class PlotLabelInjectionTest extends TestCase
{
    /** Screen clear + OSC palette/title steal + printable witness. */
    private const PAYLOAD = "\x1b[2J\x1b]0;PWN\x07EVIL";

    public function testGraphAxisLabelsNeverReachTheFrameAsEscapes(): void
    {
        $render = Graph::new()
            ->withData([1.0, 2.0, 3.0])
            ->withLabels(['a' . self::PAYLOAD, 'b', 'c'])
            ->setSize(40, 12)
            ->render();

        $this->assertStringNotContainsString("\x1b[2J", $render);
        $this->assertStringNotContainsString("\x1b]0;PWN\x07", $render);
        $this->assertStringContainsString('aEVIL', $render, 'sanitized text stays visible');
    }

    public function testDonutCenterTextIsEscapedFreeAndRowExact(): void
    {
        $donut = Donut::new([
            ['value' => 50, 'color' => Color::hex('#AABBCC'), 'label' => 'A'],
            ['value' => 50, 'color' => Color::hex('#CCBBAA'), 'label' => 'B'],
        ])
            ->withSize(21)
            ->withShowPercentage(false)
            ->withCenterValue('x' . self::PAYLOAD);

        $render = $donut->render();
        $this->assertStringNotContainsString("\x1b[2J", $render);

        $rows = explode("\n", $render);
        foreach ($rows as $row) {
            if ($row === '') {
                continue;
            }
            $this->assertSame(21, Width::string($row), 'every donut row holds the allocation in CELLS');
        }
    }

    public function testDonutWideGlyphsCostCellsNotCodepoints(): void
    {
        $render = Donut::new([
            ['value' => 50, 'color' => Color::hex('#AABBCC'), 'label' => 'A'],
            ['value' => 50, 'color' => Color::hex('#CCBBAA'), 'label' => 'B'],
        ])
            ->withSize(21)
            ->withShowPercentage(false)
            ->withCenterValue('日本語テキスト')
            ->render();

        $rows = explode("\n", $render);
        foreach ($rows as $row) {
            if ($row === '') {
                continue;
            }
            $this->assertSame(21, Width::string($row), 'a CJK run must not widen the hole row');
        }

        $centerRows = array_values(array_filter($rows, static fn(string $r): bool => str_contains($r, '日')));
        $this->assertNotEmpty($centerRows, 'the CJK run starts painting');
        foreach ($centerRows as $line) {
            $this->assertStringNotContainsString('テ', $line, 'the budget stops before the glyph it cannot fit');
        }
    }

    public function testPartitionSegmentLabelsArePaintedEscapedFree(): void
    {
        $root = (new PartitionSegment('r', 'Root', 20))
            ->withChildren([new PartitionSegment('x', 'X' . self::PAYLOAD, 10), new PartitionSegment('y', 'Y', 10)]);

        $render = Partition::new()->withRoot($root)->setSize(30, 10)->render();

        $this->assertStringNotContainsString("\x1b[2J", $render);
        $this->assertStringNotContainsString("\x1b]0;PWN\x07", $render);
        foreach (explode("\n", $render) as $row) {
            if ($row === '') {
                continue;
            }
            $this->assertLessThanOrEqual(30, Width::string($row), 'no label may push a row past the allocation');
        }
    }

    public function testWordCloudWordsAreSanitizedAtEntry(): void
    {
        $render = WordCloud::new([
            ['word' => 'go' . self::PAYLOAD, 'weight' => 5],
            ['word' => 'safe', 'weight' => 3],
        ])->setSize(40, 12)->render();

        $this->assertStringNotContainsString("\x1b[2J", $render);
        $this->assertStringNotContainsString("\x1b]0;PWN\x07", $render);
    }

    public function testGaugeDetailAndLabelAreSanitizedAtTheConstructorChoke(): void
    {
        $gauge = (new GaugeWithDetail('L' . self::PAYLOAD, 50, 100, 'd' . self::PAYLOAD))
            ->setSize(40, 8);

        // The stored strings are already clean - and the render stays clean.
        $this->assertStringNotContainsString("\x1b[2J", $gauge->render());

        $withDetail = (new GaugeWithDetail('L', 50, 100, 'd'))->withDetail('D' . self::PAYLOAD);
        $this->assertStringNotContainsString("\x1b[2J", $withDetail->render());
    }

    public function testProgressSegmentLabelsAreSanitizedAtEntry(): void
    {
        $render = Progress::segmented([
            ['label' => 'a' . self::PAYLOAD, 'ratio' => 0.5],
            ['label' => 'b', 'ratio' => 0.5],
        ])->setSize(30, 3)->render();

        $this->assertStringNotContainsString("\x1b[2J", $render);
        $this->assertStringNotContainsString("\x1b]0;PWN\x07", $render);
    }

    public function testDotMatrixContentIsSanitizedAndRenderIsMemoized(): void
    {
        $matrix = DotMatrix::new('ok' . self::PAYLOAD);
        $render = $matrix->render();
        $this->assertStringNotContainsString("\x1b[2J", $render);

        // finding sugar-dashboard-194: one build per (content, style) key.
        $counter = new \ReflectionClass(DotMatrix::class);
        $before = $counter->getStaticPropertyValue('renderBuilds');

        $plain = DotMatrix::new('hi');
        $plain->render();
        $plain->render();
        $this->assertSame(1, $counter->getStaticPropertyValue('renderBuilds') - $before, 'second render is the memo');

        $grown = $plain->withCellSize(2);
        $grown->render();
        $grown->render();
        $this->assertSame(2, $counter->getStaticPropertyValue('renderBuilds') - $before, 'a style change rebuilds once');
    }
}
