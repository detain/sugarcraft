<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Legend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Charts\Legend\Legend;
use SugarCraft\Charts\Chart\Position;

/**
 * CH-35 (sugar-charts-014): the bordered bottom legend must frame with
 * the same geometry as the top — the 3-lane convergence defect had
 * renderBottom emitting the top corner pair at full width (two columns
 * of overhang). Pins are geometric (width budgets + corner ownership),
 * never a byte copy of the fix expression.
 */
final class LegendFrameWidthTest extends TestCase
{
    /**
     * @return array<string, array{0: list<array{label: string, color: string}>}>
     */
    public static function contentProvider(): array
    {
        $many = array_map(
            static fn(int $i): array => ['label' => 's' . $i, 'color' => 'default'],
            range(0, 4),
        );

        return [
            'one item' => [[['label' => 'A', 'color' => 'red']]],
            'two items' => [[['label' => 'Alpha', 'color' => 'red'], ['label' => 'Beta', 'color' => 'green']]],
            'single cell' => [[['label' => 'x', 'color' => 'red']]],
            'wide host' => [[['label' => '温度計', 'color' => 'blue'], ['label' => '湿度', 'color' => 'orange']]],
            'many' => [$many],
        ];
    }

    /**
     * @param list<array{label: string, color: string}> $items
     */
    private function framed(array $items, Position $position): array
    {
        $out = Legend::new($items)->withPosition($position)->withShowBorder(true)->view();
        return explode("\n", $out);
    }

    /**
     * @param list<array{label: string, color: string}> $items
     */
    /** @dataProvider contentProvider */
    public function testBottomFrameWidthEqualsContentPlusTwo(array $items): void
    {
        $rows = $this->framed($items, Position::Bottom);
        $content = $rows[0];
        $frame = (string) end($rows);
        $this->assertSame(
            max(Width::string($content), 2),
            Width::string($frame),
            'bottom frame spans exactly the content width (corners inside the -2 gutter)',
        );
    }

    /**
     * @param list<array{label: string, color: string}> $items
     */
    /** @dataProvider contentProvider */
    public function testTopAndBottomFramesAreEqualWidths(array $items): void
    {
        $top = $this->framed($items, Position::Top);
        $bottom = $this->framed($items, Position::Bottom);
        $this->assertSame(
            Width::string((string) reset($top)),
            Width::string((string) end($bottom)),
            'the two frame positions must converge on one width',
        );
    }

    /**
     * @param list<array{label: string, color: string}> $items
     */
    /** @dataProvider contentProvider */
    public function testBottomPositionOwnsTheLowerCorners(array $items): void
    {
        $rows = $this->framed($items, Position::Bottom);
        $frame = (string) end($rows);
        $this->assertStringStartsWith('└', $frame);
        $this->assertStringEndsWith('┘', $frame);
        $this->assertStringNotContainsString('┌', $frame, 'no orphan top corner on a bottom frame (the defect)');
        $this->assertStringNotContainsString('┐', $frame);
    }

    public function testEmptyLegendStillFramesCleanly(): void
    {
        $rows = $this->framed([['label' => 'A', 'color' => 'red']], Position::Bottom);
        $this->assertCount(2, $rows, 'bottom legend is content row then frame row');
        $empty = Legend::new([])->withPosition(Position::Bottom)->withShowBorder(true)->view();
        $this->assertSame('', $empty, 'no items renders nothing (pre-existing empty contract)');
    }
}
