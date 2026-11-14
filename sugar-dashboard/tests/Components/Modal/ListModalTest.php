<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Modal;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Components\Modal\ListModal;

final class ListModalTest extends TestCase
{
    public function testListModalWithOnEdit(): void
    {
        $items = ['Item 1', 'Item 2', 'Item 3'];
        $calledWith = null;
        $modal = ListModal::new($items)
            ->withOnEdit(function ($item, $index) use (&$calledWith) {
                $calledWith = ['item' => $item, 'index' => $index];
            });

        $this->assertNotNull($modal);
        $rendered = $modal->render();
        $this->assertStringContainsString('[E]dit', $rendered);
    }

    public function testListModalWithOnDelete(): void
    {
        $items = ['Item 1', 'Item 2', 'Item 3'];
        $calledWith = null;
        $modal = ListModal::new($items)
            ->withOnDelete(function ($item, $index) use (&$calledWith) {
                $calledWith = ['item' => $item, 'index' => $index];
            });

        $this->assertNotNull($modal);
        $rendered = $modal->render();
        $this->assertStringContainsString('[D]elete', $rendered);
    }

    public function testListModalRendersEditDeleteHints(): void
    {
        $items = ['Apple', 'Banana', 'Cherry'];
        $modal = ListModal::new($items)
            ->withOnEdit(fn() => null)
            ->withOnDelete(fn() => null);

        $rendered = $modal->render();
        $this->assertStringContainsString('[E]dit', $rendered);
        $this->assertStringContainsString('[D]elete', $rendered);
        $this->assertStringContainsString('Apple', $rendered);
        $this->assertStringContainsString('Banana', $rendered);
        $this->assertStringContainsString('Cherry', $rendered);
    }

    public function testListModalWithoutCallbacks(): void
    {
        $items = ['Alpha', 'Beta'];
        $modal = ListModal::new($items);
        $rendered = $modal->render();

        $this->assertStringNotContainsString('[E]dit', $rendered);
        $this->assertStringNotContainsString('[D]elete', $rendered);
    }

    // ═══ D-69 (finding sugar-dashboard-064): the allocation is READ ═══

    public function testUnallocatedRenderStaysNatural(): void
    {
        $modal = ListModal::new(['Item 00', 'Item 01'], 'T')
            ->withOnEdit(static fn(...$args): ?string => null)
            ->withSelectedIndex(0);
        $this->assertSame("T\n[E]dit\n> Item 00\n  Item 01", $modal->render());

        $long = ListModal::new([str_repeat('X', 40)])->render();
        $this->assertStringContainsString(str_repeat('X', 40), $long, 'no width = no truncation');
    }

    public function testSetSizeTruncatesRowsToAllocation(): void
    {
        $modal = ListModal::new([str_repeat('X', 40), 'short'])->setSize(12, 0);
        $rows = explode("\n", $modal->render());
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(12, Width::string($row), 'rows fit the allocation');
        }
        $this->assertSame(12, Width::string($rows[0]), 'the over-long row lands exactly on the budget');
    }

    public function testSetSizeWindowsItemsAroundSelection(): void
    {
        $items = array_map(static fn(int $i): string => sprintf('Item %02d', $i), range(0, 9));
        $rendered = ListModal::new($items)->setSize(20, 4)->withSelectedIndex(9)->render();
        $rows = explode("\n", $rendered);
        $this->assertCount(4, $rows);
        $this->assertStringContainsString('Item 09', $rendered);
        $this->assertStringContainsString('Item 06', $rendered);
        $this->assertStringNotContainsString('Item 00', $rendered);
        $this->assertStringNotContainsString('Item 05', $rendered, 'zero-pad labels dodge substring collisions');
    }

    public function testSelectionBeyondEndRendersWithoutCursor(): void
    {
        $items = array_map(static fn(int $i): string => sprintf('Item %02d', $i), range(0, 9));
        $rendered = ListModal::new($items)->withSelectedIndex(999)->render();
        $this->assertStringNotContainsString('> ', $rendered, 'a past-end selection shows no phantom cursor');
        $this->assertCount(10, explode("\n", $rendered));
    }

    public function testCallbackWithersCarryTheWholeState(): void
    {
        $items = array_map(static fn(int $i): string => sprintf('Item %02d', $i), range(0, 9));
        $modal = ListModal::new($items)
            ->setSize(20, 4)
            ->withSelectedIndex(9)
            ->withOnEdit(static fn(...$args): ?string => null);
        $rendered = $modal->render();
        $this->assertStringContainsString('> Item 09', $rendered, 'the callback wither kept the cursor');
        $this->assertStringContainsString('Item 07', $rendered);
        $this->assertStringNotContainsString('Item 00', $rendered, 'the window survived the rebuild');

        $both = $modal->withOnDelete(static fn(...$args): ?string => null)->render();
        $this->assertStringContainsString('[E]dit [D]elete', $both);
        $this->assertStringContainsString('> Item 09', $both, 'both carries compose');
    }
}
