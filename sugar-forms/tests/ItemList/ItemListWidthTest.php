<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\ItemList;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;

/**
 * SF-002: view() must truncate EVERY emitted line to the declared width.
 * Pre-fix the list painted titles, indented descriptions, the filter echo,
 * the status bar and the header unclipped, so an over-long line soft-wrapped
 * in the host terminal and the declared height/mouse layout desynced.
 */
final class ItemListWidthTest extends TestCase
{
    /** @param list<string> $lines */
    private function assertAllWithinWidth(array $lines, int $max, string $context): void
    {
        foreach ($lines as $i => $line) {
            $this->assertLessThanOrEqual(
                $max,
                Width::of($line),
                "line {$i} of {$context} exceeds the {$max}-cell budget: [{$line}]"
            );
        }
    }

    public function testTwoHundredCellTitlesClipToDeclaredWidth(): void
    {
        $items = [
            new StringItem(str_repeat('A', 200)),
            new StringItem(str_repeat('B', 200)),
        ];
        $l = ItemList::new($items, 40, 5)->withTitle(str_repeat('T', 200));
        [$l, ] = $l->focus();

        $lines = explode("\n", $l->view());
        $this->assertAllWithinWidth($lines, 40, 'wide-titles view');
        // The clip is a real budget, not a soft-wrap leftover:
        $this->assertStringNotContainsString(str_repeat('A', 41), $l->view());
    }

    public function testDescriptionRowClips(): void
    {
        $described = new class implements \SugarCraft\Forms\ItemList\Item {
            public function title(): string          { return 'ok'; }
            public function description(): string    { return str_repeat('D', 150); }
            public function filterValue(): string    { return 'ok'; }
        };
        $l = ItemList::new([$described], 30, 5)->withShowDescription(true);
        [$l, ] = $l->focus();

        // The '    ' + description row is 154 cells raw — it must land
        // inside the declared 30-cell budget:
        $this->assertAllWithinWidth(explode("\n", $l->view()), 30, 'description-row view');
    }

    public function testFilterEchoRowClips(): void
    {
        $l = ItemList::new([new StringItem('ok')], 30, 5)->withShowFilter(true);
        [$l, ] = $l->focus();
        [$l, ] = $l->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, '/'));
        for ($i = 0; $i < 100; $i++) {
            [$l, ] = $l->update(new \SugarCraft\Core\Msg\KeyMsg(\SugarCraft\Core\KeyType::Char, 'f'));
        }

        $view = $l->view();
        $this->assertStringContainsString('/', $view, 'filter mode is on');
        // '/' + 100-cell echo is 101 cells raw — clipped to the budget:
        $this->assertAllWithinWidth(explode("\n", $view), 30, 'filter-echo view');
    }

    public function testWidthZeroKeepsTheLegacyBytes(): void
    {
        // width == 0 declares NO budget: the unclipped legacy path, pinned
        // byte-identical against the same content rendered without width.
        $items = [new StringItem(str_repeat('W', 300))];
        $l     = ItemList::new($items, 0, 5);
        [$l, ] = $l->focus();

        $view = $l->view();
        $this->assertStringContainsString(str_repeat('W', 300), $view, 'width-0 must not clip');
    }

    public function testSgrSurvivesTheClipAsCellsNotBytes(): void
    {
        // The reverse-video cursor segment + prefix cells must be measured
        // POST-render: SGR bytes count as zero cells, so a styled row still
        // fits the budget in visible cells and keeps its trailing reset.
        $items = [new StringItem(str_repeat('C', 60))];
        $l     = ItemList::new($items, 20, 5);
        [$l, ] = $l->focus();

        $row = $l->view();
        $this->assertAllWithinWidth(explode("\n", $row), 20, 'styled-cursor view');
        $this->assertStringContainsString("\x1b[0m", $row, 'the trailing SGR reset is harvested, not counted');
    }

    public function testWideCharactersCountCellsNotCodepoints(): void
    {
        // CJK occupies 2 cells: 25 ideographs = 50 cells → clipped to 40
        // cells = 20 ideographs visible.
        $ideograph = '中';
        $items     = [new StringItem(str_repeat($ideograph, 25))];
        $l         = ItemList::new($items, 40, 5);
        [$l, ]     = $l->focus();

        $row = explode("\n", $l->view())[0];
        $this->assertLessThanOrEqual(40, Width::of($row));
        $this->assertStringNotContainsString(str_repeat($ideograph, 21), $row);
    }
}
