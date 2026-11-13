<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Table\Table;
use SugarCraft\Widgets\Tabs\Tabs;
use SugarCraft\Widgets\Tree\Node;
use SugarCraft\Widgets\Tree\Tree;

/**
 * W-35: one law — 0 means auto/unbounded, negatives throw.
 */
final class DimensionLawTest extends TestCase
{
    /** @return list<list<string>> */
    private function rows(int $n): array
    {
        $r = [];
        for ($i = 1; $i <= $n; $i++) {
            $r[] = ["row{$i}"];
        }
        return $r;
    }

    public function testTableHeightZeroPaintsEveryRow(): void
    {
        $view = Table::new(['H'], $this->rows(25))->withSize(0, 0)->view();
        // header + 25 body rows
        self::assertCount(26, explode("\n", $view));
        self::assertStringContainsString('row25', $view);
    }

    public function testTableHeightPositiveStillWindows(): void
    {
        $view = Table::new(['H'], $this->rows(25))->withSize(0, 3)->view();
        self::assertCount(4, explode("\n", $view));
    }

    public function testTableHeightZeroWithPageSizeLetsPageSizeRule(): void
    {
        $view = Table::new(['H'], $this->rows(25))->withSize(0, 0)->withPageSize(5)->view();
        self::assertCount(6, explode("\n", $view));
    }

    public function testTableNegativeStillThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Table::new(['H'], [])->withSize(0, -1);
    }

    public function testTreeHeightZeroIsUnbounded(): void
    {
        $nodes = [];
        for ($i = 1; $i <= 15; $i++) {
            $nodes[] = Node::leaf("n{$i}");
        }
        $view = Tree::new(...$nodes)->withSize(0, 0)->view();
        self::assertStringContainsString('n15', $view);
    }

    public function testTabsDefaultWidthIsZeroNoClip(): void
    {
        $labels = array_map(static fn (int $i): string => "Tab number {$i}", range(1, 12));
        $bar = Tabs::new($labels)->view();
        self::assertStringContainsString('Tab number 12', $bar);
        self::assertStringNotContainsString('…', $bar);
        self::assertSame($bar, Tabs::new($labels)->withWidth(0)->view());
    }

    public function testTabsNegativeWidthStillThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Tabs::new(['a'])->withWidth(-1);
    }
}
