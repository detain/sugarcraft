<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Vms;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\VmGuest;
use SugarCraft\Top\Panel\Vms\VmsGrid;
use SugarCraft\Top\Panel\Vms\VmsSort;

final class VmsGridTest extends TestCase
{
    public function testColumnsFollowTheWidthAndSpanTheBox(): void
    {
        foreach ([[36, 1], [70, 1], [71, 2], [120, 3], [200, 5]] as [$width, $columns]) {
            $grid = VmsGrid::for($width, 27, 55);
            $this->assertSame($columns, $grid->columns, "width $width");
            $last = $grid->card($columns - 1);
            $this->assertSame($width - 1, $last->right(), 'the last card meets the right border');
            for ($c = 1; $c < $columns; $c++) {
                $this->assertSame($grid->card($c - 1)->right() + 1, $grid->card($c)->x, 'one blank column between cards');
            }
            $this->assertGreaterThanOrEqual(VmsGrid::CARD_MIN_WIDTH, $grid->card(0)->width + ($columns === 1 ? VmsGrid::CARD_MIN_WIDTH : 0));
        }
    }

    public function testCardsGrowWhenTheFleetFits(): void
    {
        // 55 guests never fit: compact cards, as many rows as fit.
        $paged = VmsGrid::for(120, 27, 55);
        $this->assertSame(VmsGrid::CARD_HEIGHT, $paged->cardHeight);
        $this->assertSame(4, $paged->rows);
        $this->assertSame(12, $paged->perPage());
        // 7 guests in 3 columns need 3 rows of a 25-row interior: 8 each.
        $this->assertSame(8, VmsGrid::for(120, 27, 7)->cardHeight);
        $this->assertSame(58, VmsGrid::for(120, 60, 1)->cardHeight, 'one guest fills the box');
        // A box squeezed below one card still draws one row of cut-down cards.
        $squeezed = VmsGrid::for(120, 6, 7);
        $this->assertSame([1, 4], [$squeezed->rows, $squeezed->cardHeight]);
        $this->assertSame([1, VmsGrid::CARD_MIN_HEIGHT], [VmsGrid::for(80, 5, 7)->rows, VmsGrid::for(80, 5, 7)->cardHeight]);
        $this->assertSame(0, VmsGrid::for(120, 4, 3)->rows, 'not even a title, a cpu row and a border');
    }

    public function testSlotsAndHits(): void
    {
        $grid = VmsGrid::for(120, 27, 55);
        $card = $grid->card(4); // row 1, column 1
        $this->assertSame(1 + VmsGrid::CARD_HEIGHT, $card->y);
        $this->assertSame(4, $grid->slotAt($card->x, $card->y, 12));
        $this->assertSame(4, $grid->slotAt($card->right() - 1, $card->bottom() - 1, 12));
        $this->assertNull($grid->slotAt($card->x - 1, $card->y, 12), 'the gap column');
        $this->assertNull($grid->slotAt($card->x, $card->y, 4), 'past the shown cards');
        $this->assertNull($grid->slotAt(0, 0, 12), 'the border');
    }

    public function testScrollKeepsTheSelectionInView(): void
    {
        $grid = VmsGrid::for(120, 27, 55); // 3 columns, 4 rows; 19 rows total
        $this->assertSame(19, $grid->totalRows(55));
        $this->assertSame(0, $grid->scroll(0, 11, 55));
        $this->assertSame(1, $grid->scroll(0, 12, 55), 'row 4 pulls the view down one row');
        $this->assertSame(15, $grid->scroll(0, 54, 55), 'the last page is full');
        $this->assertSame(2, $grid->scroll(9, 6, 55), 'scrolling back up to the selection');
        $this->assertSame(15, $grid->scroll(99, -1, 55), 'clamped without a selection');
        $this->assertSame(0, $grid->scroll(5, -1, 3));
    }

    public function testSortOrders(): void
    {
        $g = static fn (string $name, float $cpu, int $mem, float $disk, float $net, float $psi): VmGuest => new VmGuest(
            '/s/' . $name,
            $name,
            memBytes: 100,
            cpu: $cpu,
            memUsed: $mem,
            diskRead: $disk,
            diskWrite: 0.0,
            netRx: $net,
            netTx: -1.0,
            psiCpu: $psi,
            psiMem: -1.0,
            psiIo: 0.0,
        );
        $guests = [$g('b', 10.0, 90, 1.0, 5.0, 0.0), $g('a', 50.0, 10, -1.0, 9.0, 30.0), $g('c', 10.0, 50, 7.0, -1.0, 2.0)];
        $names = static fn (array $list): string => implode('', array_map(static fn (VmGuest $x): string => $x->name, $list));
        $this->assertSame('abc', $names(VmsSort::sorted($guests, 'cpu')), 'ties by name');
        $this->assertSame('bca', $names(VmsSort::sorted($guests, 'mem')));
        $this->assertSame('cba', $names(VmsSort::sorted($guests, 'disk')), 'unmeasured counts 0');
        $this->assertSame('abc', $names(VmsSort::sorted($guests, 'net')));
        $this->assertSame('acb', $names(VmsSort::sorted($guests, 'psi')));
        $this->assertSame('abc', $names(VmsSort::sorted($guests, 'name')));
        $this->assertSame('abc', $names(VmsSort::sorted($guests, 'bogus')), 'unknown = cpu');

        $this->assertSame('mem', VmsSort::cycled('cpu', 1));
        $this->assertSame('name', VmsSort::cycled('cpu', -1));
        $this->assertSame('cpu', VmsSort::cycled('name', 1));
        $this->assertSame('cpu', VmsSort::cycled('bogus', 1));
    }
}
