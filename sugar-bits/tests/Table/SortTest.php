<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use SugarCraft\Bits\Table\SortDirection;
use SugarCraft\Bits\Table\SortState;
use SugarCraft\Bits\Table\Table;
use PHPUnit\Framework\TestCase;

final class SortTest extends TestCase
{
    public function testSortDirectionToggle(): void
    {
        $this->assertSame(SortDirection::Desc, SortDirection::Asc->toggle());
        $this->assertSame(SortDirection::Asc, SortDirection::Desc->toggle());
    }

    public function testSortStateEmpty(): void
    {
        $state = SortState::empty();
        $this->assertTrue($state->isEmpty());
        $this->assertSame([], $state->criteria);
    }

    public function testSortStateWithCriterion(): void
    {
        $state = SortState::empty()->withCriterion(0, SortDirection::Asc);
        $this->assertFalse($state->isEmpty());
        $this->assertSame([[0, SortDirection::Asc]], $state->criteria);
    }

    public function testSortStateChainedCriteria(): void
    {
        $state = SortState::empty()
            ->withCriterion(0, SortDirection::Asc)
            ->withCriterion(1, SortDirection::Desc);
        $this->assertSame([
            [0, SortDirection::Asc],
            [1, SortDirection::Desc],
        ], $state->criteria);
    }

    public function testWithSortSingleColumn(): void
    {
        $t = Table::new(
            ['Name', 'Age'],
            [
                ['Alice', '30'],
                ['Bob', '25'],
                ['Carol', '40'],
                ['Dave', '35'],
            ],
        );

        $t2 = $t->withSort('Name', SortDirection::Asc);
        // rowsList returns original order; view() applies sort
        $this->assertSame(['Alice', '30'], $t2->rowsList()[0]);
        $this->assertNotSame($t, $t2);
        // Verify view() output reflects sorted order: Alice before Bob
        $view = $t2->view();
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringContainsString('Bob', $view);
        // assertLessThan(expected, actual) checks actual < expected
        // We want Alice pos < Bob pos, so: assertLessThan(BobPos, AlicePos)
        $this->assertLessThan(strpos($view, 'Bob'), strpos($view, 'Alice'));
    }

    public function testWithSortDesc(): void
    {
        $t = Table::new(
            ['Name', 'Age'],
            [
                ['Alice', '30'],
                ['Bob', '25'],
                ['Carol', '40'],
                ['Dave', '35'],
            ],
        );

        $t2 = $t->withSort('Age', SortDirection::Desc);
        $view = $t2->view();
        // Carol (40) should appear before Alice (30) in the output
        // assertLessThan(expected, actual) checks actual < expected
        // We want Carol pos < Alice pos, so: assertLessThan(AlicePos, CarolPos)
        $this->assertLessThan(strpos($view, 'Alice'), strpos($view, 'Carol'));
    }

    public function testThenSortByMultiColumn(): void
    {
        $t = Table::new(
            ['Name', 'Age'],
            [
                ['Alice', '30'],
                ['Bob', '30'],
                ['Carol', '25'],
                ['Dave', '25'],
            ],
        );

        // Primary sort: Age asc. Tiebreaker: Name asc.
        $t2 = $t
            ->withSort('Age', SortDirection::Asc)
            ->thenSortBy('Name', SortDirection::Asc);

        $view = $t2->view();
        // Carol and Dave (age 25) should come before Alice and Bob (age 30)
        // assertLessThan(expected, actual) checks actual < expected
        // We want Carol pos < Alice pos, so: assertLessThan(AlicePos, CarolPos)
        $this->assertLessThan(strpos($view, 'Alice'), strpos($view, 'Carol'));
        $this->assertLessThan(strpos($view, 'Bob'), strpos($view, 'Carol'));
        $this->assertLessThan(strpos($view, 'Alice'), strpos($view, 'Dave'));
        $this->assertLessThan(strpos($view, 'Bob'), strpos($view, 'Dave'));
    }

    public function testClearSort(): void
    {
        $t = Table::new(
            ['Name', 'Age'],
            [
                ['Alice', '30'],
                ['Bob', '25'],
            ],
        );

        $t2 = $t->withSort('Name', SortDirection::Asc);
        $t3 = $t2->clearSort();
        // clearSort should reset sort state
        $this->assertTrue($t3->getSortState()->isEmpty());
        // rowsList should be unchanged (original order)
        $this->assertSame($t->rowsList(), $t3->rowsList());
    }

    public function testSortWithInvalidColumnThrows(): void
    {
        $t = Table::new(['Name'], [['Alice']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sort column not found');
        $t->withSort('NonExistent');
    }

    public function testSortOnEmptyTable(): void
    {
        $t = Table::new(['Name'], []);
        $t2 = $t->withSort('Name', SortDirection::Asc);
        $this->assertSame([], $t2->rowsList());
    }

    public function testSortStateIsEmptyOnNewTable(): void
    {
        $t = Table::new(['Name'], [['Alice']]);
        $this->assertTrue($t->getSortState()->isEmpty());
    }

    public function testSortGetSortStateAfterWithSort(): void
    {
        $t = Table::new(['Name'], [['Alice']]);
        $t2 = $t->withSort('Name', SortDirection::Asc);
        $state = $t2->getSortState();
        $this->assertFalse($state->isEmpty());
        $this->assertSame([[0, SortDirection::Asc]], $state->criteria);
    }

    public function testSortGetSortStateAfterThenSortBy(): void
    {
        $t = Table::new(['Name', 'Age'], [['Alice', '30']]);
        $t2 = $t->withSort('Age', SortDirection::Asc)->thenSortBy('Name', SortDirection::Desc);
        $state = $t2->getSortState();
        $this->assertSame([
            [1, SortDirection::Asc],
            [0, SortDirection::Desc],
        ], $state->criteria);
    }

    public function testWithSortDefaultDirection(): void
    {
        $t = Table::new(
            ['Name'],
            [
                ['Zoe'],
                ['Alice'],
            ],
        );

        // Using default SortDirection::Asc
        $t2 = $t->withSort('Name');
        $view = $t2->view();
        // Zoe comes before Alice alphabetically, so with Asc sort Alice should appear first
        $alicePos = strpos($view, 'Alice');
        $zoePos = strpos($view, 'Zoe');
        $this->assertNotFalse($alicePos);
        $this->assertNotFalse($zoePos);
        // assertLessThan(expected, actual) checks actual < expected
        // We want Alice pos < Zoe pos, so: assertLessThan(ZoePos, AlicePos)
        $this->assertLessThan(strpos($view, 'Zoe'), strpos($view, 'Alice'));
    }

    public function testSortedRowsAffectView(): void
    {
        $t = Table::new(
            ['Name', 'Age'],
            [
                ['Zoe', '30'],
                ['Alice', '25'],
            ],
            0,
            10,
        );
        [$t, ] = $t->focus();

        $t2 = $t->withSort('Name', SortDirection::Asc);
        $view = $t2->view();

        // Alice should appear before Zoe in the rendered output
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringContainsString('Zoe', $view);
        // assertLessThan(expected, actual) checks actual < expected
        // We want Alice pos < Zoe pos, so: assertLessThan(ZoePos, AlicePos)
        $this->assertLessThan(strpos($view, 'Zoe'), strpos($view, 'Alice'));
    }

    public function testSortThenSetRows(): void
    {
        $t = Table::new(['Name'], [['Zoe']])
            ->withSort('Name', SortDirection::Asc);

        $t2 = $t->setRows([['Alice'], ['Bob']]);
        // After setRows, sort should still be active on new data
        $this->assertSame(['Alice'], $t2->rowsList()[0]);
        $this->assertSame(['Bob'], $t2->rowsList()[1]);
    }

    public function testSelectedRowMatchesViewAfterSort(): void
    {
        $t = Table::new(
            ['Name'],
            [['Charlie'], ['Alice'], ['Bob']],
        )->withSort('Name', SortDirection::Asc);

        // After sort ascending, cursor=0 should point to 'Alice' (first alphabetically).
        // selectedRow() must return the row that view() highlights at cursor position.
        $this->assertSame(['Alice'], $t->selectedRow());
    }

    public function testCursorClampsToFilteredCount(): void
    {
        $t = Table::new(
            ['Name'],
            [['Alice'], ['Bob'], ['Carol']],
        )
            ->withFilterable(true)
            ->withFilter('Alice')
            ->focus()[0];

        // Only Alice matches the filter — one visible row.
        // setCursor(PHP_INT_MAX) should clamp to the single visible row.
        $t2 = $t->setCursor(PHP_INT_MAX);
        $this->assertSame(0, $t2->index());
        $this->assertSame(['Alice'], $t2->selectedRow());
    }
}
