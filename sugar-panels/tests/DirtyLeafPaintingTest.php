<?php

declare(strict_types=1);

namespace SugarCraft\Panels\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Panels\Node;
use SugarCraft\Panels\SugarPanels;

/**
 * Same-size frames with the same layout repaint only the leaves whose paint
 * inputs changed, and the retained grid stays the one a full repaint makes.
 */
final class DirtyLeafPaintingTest extends TestCase
{
    /** @return list<list<string>> */
    private static function grid(SugarPanels $panels): array
    {
        $cells = (new \ReflectionProperty($panels, 'previousCells'))->getValue($panels);
        self::assertIsArray($cells);

        return $cells;
    }

    private static function layout(string $a, string $b, string $c): Node
    {
        return Node::horizontal(
            Node::leaf($a)->withBorder(false),
            Node::vertical(Node::leaf($b), Node::leaf($c)->withBorder(false))->withBorder(false),
        )->withBorder(false);
    }

    public function testOnlyTheChangedLeafRepaints(): void
    {
        $panels = SugarPanels::new();
        $panels->render(self::layout('a', 'b', 'c'), 20, 6);
        $this->assertSame(3, $panels->paintedLeaves(), 'a first frame paints every leaf');

        $panels->render(self::layout('a', 'B!', 'c'), 20, 6);
        $this->assertSame(1, $panels->paintedLeaves());

        $panels->render(self::layout('a', 'B!', 'c'), 20, 6);
        $this->assertSame(0, $panels->paintedLeaves(), 'an unchanged frame paints nothing');
    }

    public function testAChangedLayoutRepaintsEveryLeaf(): void
    {
        $panels = SugarPanels::new();
        $panels->render(self::layout('a', 'b', 'c'), 20, 6);
        $panels->render(self::layout('a', 'b', 'c')->withSpacing(1), 20, 6);

        $this->assertSame(3, $panels->paintedLeaves());
    }

    public function testTheDeltaCarriesOnlyTheChangedCells(): void
    {
        $panels = SugarPanels::new();
        $panels->render(self::layout('a', 'b', 'c'), 20, 6);

        $delta = $panels->render(self::layout('a', 'Z', 'c'), 20, 6);

        $this->assertStringContainsString('Z', $delta);
        $this->assertStringNotContainsString('a', \preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $delta) ?? '');
    }

    /**
     * A repainted leaf can flip a divider: text reaching the last column makes
     * the separator stand down, and clearing it brings the separator back. The
     * retained grid must match what a fresh renderer paints for that frame.
     */
    public function testDividerVerdictsFollowTheRepaintedLeaf(): void
    {
        $frames = [
            ['', 'b', 'c'],
            [\str_repeat('x', 10), 'b', 'c'],
            ['', 'b', 'c'],
            ['short', "b\nb\nb\nb\nb", ''],
            ['', '', \str_repeat('y', 30)],
        ];

        $panels = SugarPanels::new();
        foreach ($frames as [$a, $b, $c]) {
            $panels->render(self::layout($a, $b, $c), 20, 6);
            $fresh = SugarPanels::new();
            $fresh->render(self::layout($a, $b, $c), 20, 6);

            $this->assertSame(self::grid($fresh), self::grid($panels), "frame [{$a}|{$b}|{$c}]");
        }
    }

    public function testFocusAndScrollChangesRepaintTheirLeafOnly(): void
    {
        $tree = static fn (bool $focused, int $scroll): Node => Node::horizontal(
            Node::leaf("1\n2\n3\n4")->withScrollOffset($scroll),
            Node::leaf('other')->withFocused($focused),
        )->withBorder(false);

        $panels = SugarPanels::new();
        $panels->render($tree(false, 0), 20, 4);

        $panels->render($tree(true, 0), 20, 4);
        $this->assertSame(1, $panels->paintedLeaves());

        $panels->render($tree(true, 2), 20, 4);
        $this->assertSame(1, $panels->paintedLeaves());

        $fresh = SugarPanels::new();
        $fresh->render($tree(true, 2), 20, 4);
        $this->assertSame(self::grid($fresh), self::grid($panels));
    }

    public function testResetForcesAFullRepaint(): void
    {
        $panels = SugarPanels::new();
        $panels->render(self::layout('a', 'b', 'c'), 20, 6);
        $panels->resetPreviousFrame();
        $panels->render(self::layout('a', 'b', 'c'), 20, 6);

        $this->assertSame(3, $panels->paintedLeaves());
    }
}
