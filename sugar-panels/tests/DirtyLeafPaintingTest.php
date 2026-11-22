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

    /**
     * The safety check is deferred to the first same-structure frame: a fresh
     * instance per frame, or a layout that changes every frame, never pays for
     * it (it used to run on every structure change, quadratic in the leaves).
     */
    public function testTheSafetyCheckWaitsForASameStructureFrame(): void
    {
        $safe = static fn (SugarPanels $p): ?bool => (new \ReflectionProperty($p, 'previousSafe'))->getValue($p);

        $panels = SugarPanels::new();
        $panels->render(self::layout('a', 'b', 'c'), 20, 6);
        $this->assertNull($safe($panels), 'a first frame leaves the check undecided');

        $panels->render(self::layout('a', 'b', 'c')->withSpacing(1), 20, 6);
        $this->assertNull($safe($panels), 'a changed layout leaves it undecided');

        $panels->render(self::layout('a', 'B', 'c')->withSpacing(1), 20, 6);
        $this->assertTrue($safe($panels));
        $this->assertSame(1, $panels->paintedLeaves());
    }

    /**
     * The one-pass dirtySafe() must give the verdict of the pairwise rule it
     * replaced, on random display lists that overlap, overflow and clip.
     */
    public function testDirtySafeMatchesThePairwiseRule(): void
    {
        $panels = SugarPanels::new();
        $method = new \ReflectionMethod($panels, 'dirtySafe');
        $rng = new \Random\Randomizer(new \Random\Engine\Mt19937(20261122));
        $kinds = ['leaf', 'leaf', 'frame', 'v', 'h'];
        $verdicts = [true => 0, false => 0];

        for ($case = 0; $case < 3000; $case++) {
            $width = $rng->getInt(1, 12);
            $height = $rng->getInt(1, 8);
            $ops = [];
            for ($n = $rng->getInt(1, 6); $n > 0; $n--) {
                $ops[] = [
                    't' => $kinds[$rng->getInt(0, 4)],
                    'x' => $rng->getInt(-3, 13),
                    'y' => $rng->getInt(-3, 9),
                    'w' => $rng->getInt(-1, 10),
                    'h' => $rng->getInt(-1, 7),
                ];
            }
            $expected = self::pairwiseSafe($ops, $width, $height);
            $verdicts[$expected]++;

            $this->assertSame($expected, $method->invoke($panels, $ops, $width, $height), \json_encode([$width, $height, $ops]) ?: '');
        }
        $this->assertGreaterThan(100, $verdicts[true]);
        $this->assertGreaterThan(100, $verdicts[false]);
    }

    /**
     * The pairwise rule dirtySafe() implemented before it went one-pass, with
     * one correction: an op whose clipped rect is empty paints no cell, so it
     * cannot write into anything (the old rule let a zero-width rect count as
     * overlapping and fell back to a full repaint for nothing).
     *
     * @param list<array<string, mixed>> $ops
     */
    private static function pairwiseSafe(array $ops, int $width, int $height): bool
    {
        $clip = static fn (array $op): array => [
            \max(0, $op['x']),
            \max(0, $op['y']),
            \min($width, $op['x'] + \max(0, $op['w'])),
            \min($height, $op['y'] + \max(0, $op['h'])),
        ];
        $paintsInto = static function (array $op, array $rect, array $target): bool {
            if ($rect[0] >= $rect[2] || $rect[1] >= $rect[3]) {
                return false;
            }
            if ($rect[0] >= $target[2] || $target[0] >= $rect[2] || $rect[1] >= $target[3] || $target[1] >= $rect[3]) {
                return false;
            }
            if ($op['t'] !== 'frame') {
                return true;
            }
            if ($op['w'] < 2 || $op['h'] < 2) {
                return false;
            }

            return !($target[0] >= $op['x'] + 1 && $target[2] <= $op['x'] + $op['w'] - 1
                && $target[1] >= $op['y'] + 1 && $target[3] <= $op['y'] + $op['h'] - 1);
        };

        $rects = \array_map($clip, $ops);
        foreach ($ops as $i => $op) {
            if ($op['t'] === 'frame') {
                continue;
            }
            $mine = $rects[$i];
            if ($mine[0] >= $mine[2] || $mine[1] >= $mine[3]) {
                continue;
            }
            foreach ($ops as $j => $other) {
                if ($j === $i) {
                    continue;
                }
                $relevant = $j < $i ? $op['t'] === 'leaf' : ($other['t'] === 'leaf' || $other['t'] === 'frame');
                if ($relevant && $paintsInto($other, $rects[$j], $mine)) {
                    return false;
                }
            }
        }

        return true;
    }
}
