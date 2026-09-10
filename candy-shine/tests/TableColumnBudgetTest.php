<?php

declare(strict_types=1);

namespace SugarCraft\Shine\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Shine\Renderer;
use SugarCraft\Shine\Theme;

/**
 * E49 — `withTableColumnBudget()` bounds a table's TOTAL width to the
 * word-wrap width via a per-column budget; `withTableWrap(true)` alone
 * wraps each cell at the full width and therefore cannot bound the table.
 */
final class TableColumnBudgetTest extends TestCase
{
    private const WORDS = 'alpha bravo charlie delta echo foxtrot golf hotel india juliett';

    private function wideTable(): string
    {
        return implode("\n", [
            '| A | B | C |',
            '| - | - | - |',
            '| ' . self::WORDS . ' | ' . self::WORDS . ' | ' . self::WORDS . ' |',
        ]);
    }

    /** @return list<int> display width of every physical line */
    private function lineWidths(string $rendered): array
    {
        return array_map(
            static fn (string $line): int => Width::of($line),
            explode("\n", $rendered),
        );
    }

    public function testDefaultBehaviourIsByteIdenticalToTheLegacyRenderer(): void
    {
        $legacy = (new Renderer(Theme::plain(), 60))->tableWrap(true)->render($this->wideTable());
        $budgetOff = (new Renderer(Theme::plain(), 60))->tableWrap(true)
            ->tableColumnBudget(false)->render($this->wideTable());

        $this->assertSame($legacy, $budgetOff, 'the new option must be inert when off');
    }

    public function testLegacyCellWrapCannotBoundTheTable(): void
    {
        // The measured E49 defect, kept as a characterisation pin: wrap-only
        // still overflows a 60-wide pane.
        $rendered = (new Renderer(Theme::plain(), 60))->tableWrap(true)->render($this->wideTable());

        $this->assertGreaterThan(60, max($this->lineWidths($rendered)));
    }

    public function testColumnBudgetFitsEveryRowIntoTheWrapWidth(): void
    {
        $rendered = (new Renderer(Theme::plain(), 60))->tableWrap(true)
            ->tableColumnBudget(true)->render($this->wideTable());

        foreach ($this->lineWidths($rendered) as $w) {
            $this->assertLessThanOrEqual(60, $w, 'bordered rows must stay within the pane budget');
        }
        // One physical row per logical row: the budget clips, never reflows.
        $this->assertCount(5, explode("\n", trim($rendered)));
    }

    public function testBudgetClipsWideColumnsProportionallyNotEqually(): void
    {
        $md = implode("\n", [
            '| short | a much longer column indeed | mid column here |',
            '| - | - | - |',
            '| x | y | z |',
        ]);
        $rendered = (new Renderer(Theme::plain(), 40))->tableColumnBudget(true)->render($md);

        $this->assertLessThanOrEqual(40, max($this->lineWidths($rendered)));
        // Proportional budgets [3,17,9]: the wide column keeps its readable
        // head and loses the tail — clip, documented, not equal thirds.
        $this->assertStringContainsString('a much longer', $rendered);
        $this->assertStringNotContainsString('indeed', $rendered);
    }

    public function testNarrowTablesAreUntouchedByTheBudget(): void
    {
        $md = "| a | b |\n| - | - |\n| 1 | 2 |";
        $plain = (new Renderer(Theme::plain(), 60))->tableWrap(true)->render($md);
        $budgeted = (new Renderer(Theme::plain(), 60))->tableWrap(true)
            ->tableColumnBudget(true)->render($md);

        $this->assertSame($plain, $budgeted, 'columns never grow, only shrink to fit');
    }

    public function testBudgetBoundsTheTableWithoutAnyWrapFlag(): void
    {
        // The budget is its own geometry strategy: it clips at the column,
        // so `withTableWrap` stays the legacy reflow knob it always was.
        $rendered = (new Renderer(Theme::plain(), 60))->tableColumnBudget(true)
            ->render($this->wideTable());

        $this->assertLessThanOrEqual(60, max($this->lineWidths($rendered)));
    }

    public function testNoWrapWidthMeansNoBudget(): void
    {
        $rendered = (new Renderer(Theme::plain(), null))->tableWrap(true)
            ->tableColumnBudget(true)->render($this->wideTable());

        // wrapWidth null → tableWrap was already inert before E49; the
        // budget must not conjure a bound either.
        $this->assertGreaterThan(60, max($this->lineWidths($rendered)));
    }
}
