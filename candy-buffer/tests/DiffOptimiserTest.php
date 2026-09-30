<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffOptimiser;
use SugarCraft\Buffer\Diff\EraseRunOp;
use SugarCraft\Buffer\Diff\MoveCursorOp;
use SugarCraft\Buffer\Diff\RepeatRunOp;
use SugarCraft\Buffer\Diff\SetCellOp;
use SugarCraft\Buffer\Diff\SetHyperlinkOp;
use SugarCraft\Buffer\Diff\SetStyleOp;
use SugarCraft\Buffer\Style;

final class DiffOptimiserTest extends TestCase
{
    private DiffOptimiser $optimiser;

    protected function setUp(): void
    {
        $this->optimiser = new DiffOptimiser();
    }

    public function testOptimiseEmptyOps(): void
    {
        $result = $this->optimiser->optimise([]);

        $this->assertSame([], $result);
    }

    public function testOptimiseCollapseAdjacentSetStyleOps(): void
    {
        $ops = [
            new SetStyleOp(Style::new(null, null, Style::ATTR_BOLD)),
            new SetStyleOp(Style::new(null, null, Style::ATTR_ITALIC)),
            new SetStyleOp(Style::new(null, null, Style::ATTR_UNDERLINE)),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(1, $result);
        $this->assertEquals(Style::new(null, null, Style::ATTR_UNDERLINE), $result[0]->style);
    }

    public function testOptimisePreservesNonStyleBetweenStyles(): void
    {
        $ops = [
            new SetStyleOp(Style::new(null, null, Style::ATTR_BOLD)),
            new MoveCursorOp(5, 0),
            new SetStyleOp(Style::new(null, null, Style::ATTR_ITALIC)),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(3, $result);
    }

    public function testOptimiseMergeCellSpansSameStyle(): void
    {
        $style = Style::new(null, null, Style::ATTR_BOLD);
        $ops = [
            new SetCellOp([Cell::new('A', $style)]),
            new SetCellOp([Cell::new('B', $style)]),
        ];
        $result = $this->optimiser->optimise($ops);

        // Styled spans now merge: the first op seeds the span buffer (the
        // old loop only ever merged ops whose style/link were null, so this
        // pair passed through unmerged despite the same-value style).
        $this->assertCount(1, $result);
        $this->assertCount(2, $result[0]->cells);
        $this->assertSame('A', $result[0]->cells[0]->rune());
        $this->assertSame('B', $result[0]->cells[1]->rune());
    }

    public function testOptimiseDoesNotMergeDifferentStyles(): void
    {
        $styleA = Style::new(null, null, Style::ATTR_BOLD);
        $styleB = Style::new(null, null, Style::ATTR_ITALIC);
        $ops = [
            new SetCellOp([Cell::new('A', $styleA)]),
            new SetCellOp([Cell::new('B', $styleB)]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimiseMergesCellSpansWithEqualLinks(): void
    {
        // Value-equal but unshared Hyperlink instances must merge: the old
        // canMergeWithBuffer typed $bufferLink as ?string and compared a url
        // against a Hyperlink object — never true, so linked spans never merged.
        $ops = [
            new SetCellOp([Cell::new('A', null, new \SugarCraft\Buffer\Hyperlink('https://m.dev'))]),
            new SetCellOp([Cell::new('B', null, new \SugarCraft\Buffer\Hyperlink('https://m.dev'))]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(1, $result);
        $this->assertCount(2, $result[0]->cells);
    }

    public function testOptimiseDoesNotMergeLinkIdOnlyDifferences(): void
    {
        // Same url, different id: a real link change (the OSC 8 id is part of
        // the link identity), so the spans must stay separate.
        $ops = [
            new SetCellOp([Cell::new('A', null, new \SugarCraft\Buffer\Hyperlink('https://m.dev', 'v1'))]),
            new SetCellOp([Cell::new('B', null, new \SugarCraft\Buffer\Hyperlink('https://m.dev', 'v2'))]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimiseDoesNotMergeLinkIntoUnlinkedSpan(): void
    {
        $ops = [
            new SetCellOp([Cell::new('A')]),
            new SetCellOp([Cell::new('B', null, new \SugarCraft\Buffer\Hyperlink('https://m.dev'))]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimiseDoesNotMergeDifferentLinks(): void
    {
        $linkA = new \SugarCraft\Buffer\Hyperlink('https://a.com');
        $linkB = new \SugarCraft\Buffer\Hyperlink('https://b.com');
        $ops = [
            new SetCellOp([Cell::new('A', null, $linkA)]),
            new SetCellOp([Cell::new('B', null, $linkB)]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimisePreservesMoveCursorOp(): void
    {
        $ops = [
            new MoveCursorOp(5, 2),
            new MoveCursorOp(10, 2),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(MoveCursorOp::class, $result[0]);
        $this->assertInstanceOf(MoveCursorOp::class, $result[1]);
    }

    public function testOptimisePreservesEraseRunOp(): void
    {
        $ops = [
            new EraseRunOp(5),
            new EraseRunOp(3),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimisePreservesRepeatRunOp(): void
    {
        $ops = [
            new RepeatRunOp('X', 5),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(1, $result);
    }

    public function testOptimisePreservesSetHyperlinkOp(): void
    {
        $link = new \SugarCraft\Buffer\Hyperlink('https://example.com');
        $ops = [
            new SetHyperlinkOp($link),
            new SetHyperlinkOp(null),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(2, $result);
    }

    public function testOptimiseRealisticDiffSequence(): void
    {
        $bold = Style::new(null, null, Style::ATTR_BOLD);
        $ops = [
            new SetStyleOp($bold),
            new SetCellOp([Cell::new('H', $bold)]),
            new SetCellOp([Cell::new('e', $bold)]),
            new SetStyleOp(Style::new()),
            new SetCellOp([Cell::new('l', null)]),
            new SetCellOp([Cell::new('l', null)]),
            new SetCellOp([Cell::new('o', null)]),
        ];
        $result = $this->optimiser->optimise($ops);

        $this->assertNotEmpty($result);
        foreach ($result as $op) {
            $this->assertInstanceOf(\SugarCraft\Buffer\Diff\DiffOp::class, $op);
        }
    }

    public function testOptimiseSingleOpPassThrough(): void
    {
        $ops = [new MoveCursorOp(0, 0)];
        $result = $this->optimiser->optimise($ops);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]->col);
        $this->assertSame(0, $result[0]->row);
    }

    public function testOptimiseMergesMultipleAdjacentSameStyleCells(): void
    {
        $style = Style::bold();
        $ops = [
            new SetCellOp([Cell::new('A', $style)]),
            new SetCellOp([Cell::new('B', $style)]),
            new SetCellOp([Cell::new('C', $style)]),
        ];
        $result = $this->optimiser->optimise($ops);

        // After merge, all cells should be in one SetCellOp
        $setCells = array_filter($result, fn($op) => $op instanceof SetCellOp);
        $this->assertNotEmpty($setCells);
    }

    public function testOptimiseEmptySetCellOpMergesIntoPrevious(): void
    {
        // An empty SetCellOp between two same-style cells merges them
        $ops = [
            new SetCellOp([Cell::new('A')]),
            new SetCellOp([]), // empty - no-op but merges with adjacent
            new SetCellOp([Cell::new('B')]),
        ];
        $result = $this->optimiser->optimise($ops);

        // Empty op merges with neighbors of same style into one SetCellOp
        $setCells = array_filter($result, fn($op) => $op instanceof SetCellOp);
        $this->assertCount(1, $setCells);
        $cells = array_values($setCells)[0]->cells;
        $this->assertCount(2, $cells);
        $this->assertSame('A', $cells[0]->rune());
        $this->assertSame('B', $cells[1]->rune());
    }
}
