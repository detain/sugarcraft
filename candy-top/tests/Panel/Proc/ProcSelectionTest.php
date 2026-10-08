<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Panel\Proc\ProcSelection;

/**
 * btop Proc::selection, key by key, with 100 processes and 10 visible rows.
 */
final class ProcSelectionTest extends TestCase
{
    private const N = 100;
    private const MAX = 10;

    /** @return iterable<string, array{array{int, int, int}, string, array{int, int, int}}> */
    public static function moves(): iterable
    {
        // [start, selected, lastSelected] --key--> [start, selected, lastSelected]
        yield 'down from nothing selects row 1' => [[0, 0, 0], 'down', [0, 1, 0]];
        yield 'down moves the bar' => [[0, 3, 0], 'down', [0, 4, 0]];
        yield 'down at the bottom row scrolls' => [[5, 10, 0], 'down', [6, 10, 0]];
        yield 'down at the list end stays (clamped)' => [[90, 10, 0], 'down', [90, 10, 0]];
        yield 'down restores the detail row' => [[0, 0, 7], 'down', [0, 7, 0]];
        yield 'up moves the bar' => [[0, 4, 0], 'up', [0, 3, 0]];
        yield 'up at row 1 scrolls' => [[5, 1, 0], 'up', [4, 1, 0]];
        yield 'up at the top deselects' => [[0, 1, 0], 'up', [0, 0, 0]];
        yield 'up with nothing selected is a no-op' => [[3, 0, 2], 'up', [3, 0, 2]];
        yield 'up forgets the detail row' => [[0, 4, 7], 'up', [0, 3, 0]];
        yield 'page_up scrolls a page' => [[25, 4, 0], 'page_up', [15, 4, 0]];
        yield 'page_up at the top deselects' => [[0, 4, 0], 'page_up', [0, 0, 0]];
        yield 'page_up clamps at 0' => [[4, 0, 0], 'page_up', [0, 0, 0]];
        yield 'page_down scrolls a page' => [[0, 4, 0], 'page_down', [10, 4, 0]];
        yield 'page_down at the end selects the last row' => [[90, 4, 0], 'page_down', [90, 10, 0]];
        yield 'page_down clamps to the last page' => [[85, 0, 0], 'page_down', [90, 0, 0]];
        yield 'home' => [[40, 6, 0], 'home', [0, 1, 0]];
        yield 'home without selection' => [[40, 0, 0], 'home', [0, 0, 0]];
        yield 'end' => [[0, 3, 0], 'end', [90, 10, 0]];
        yield 'end without selection' => [[0, 0, 0], 'end', [90, 0, 0]];
        yield 'wheel up 3' => [[10, 2, 0], 'mouse_scroll_up', [7, 2, 0]];
        yield 'wheel up clamps at 0' => [[2, 0, 0], 'mouse_scroll_up', [0, 0, 0]];
        yield 'wheel down 3' => [[10, 2, 0], 'mouse_scroll_down', [13, 2, 0]];
        yield 'wheel down clamps at the last page' => [[89, 0, 0], 'mouse_scroll_down', [90, 0, 0]];
        yield 'unknown key' => [[3, 2, 1], 'bogus', [3, 2, 1]];
    }

    /**
     * @param array{int, int, int} $from
     * @param array{int, int, int} $to
     */
    #[DataProvider('moves')]
    public function testMove(array $from, string $key, array $to): void
    {
        $s = ProcSelection::new(...$from)->move($key, self::N, self::MAX);
        $this->assertSame($to, [$s->start, $s->selected, $s->lastSelected]);
    }

    public function testProportionalScrollbarMath(): void
    {
        // btop draw §3.5: start = round(y * (numpids - selectMax - 2) / (selectMax - 2))
        $this->assertSame(0, ProcSelection::scrollTo(0, 100, 10));
        $this->assertSame((int) round(4 * 88 / 8), ProcSelection::scrollTo(4, 100, 10));
        $this->assertSame(55, ProcSelection::scrollTo(5, 100, 10), 'round half away from zero');
        $this->assertSame(90, ProcSelection::scrollTo(9, 100, 10), 'clamped to the last page');
        $this->assertSame(0, ProcSelection::scrollTo(5, 8, 10), 'a list that fits never scrolls');
        $this->assertSame(0, ProcSelection::scrollTo(-3, 100, 10));
        $this->assertSame(98, ProcSelection::scrollTo(3, 100, 2), 'a 2-row track divides by 1, not 0');
        $this->assertSame([55, 4, 0], (static function (): array {
            $s = ProcSelection::new(0, 4)->move('mousey5', 100, 10);

            return [$s->start, $s->selected, $s->lastSelected];
        })());
    }

    public function testThumbPosition(): void
    {
        // clamp(round(start * selectMax / (numpids - selectMax)), 0, height - 5)
        $this->assertSame(0, ProcSelection::thumb(0, 100, 10, 13));
        $this->assertSame(5, ProcSelection::thumb(45, 100, 10, 13));
        $this->assertSame(8, ProcSelection::thumb(90, 100, 10, 13), 'clamped to height - 5');
        $this->assertSame(0, ProcSelection::thumb(0, 5, 10, 13), 'no scrollbar when the list fits');
    }

    public function testClampIsProcDrawsBoundsCheck(): void
    {
        $s = ProcSelection::new(50, 9)->clamp(20, 10);
        $this->assertSame([10, 9], [$s->start, $s->selected]);
        $s = ProcSelection::new(3, 9)->clamp(5, 10);
        $this->assertSame([0, 5], [$s->start, $s->selected], 'short list: no scroll, bar on the last process');
        $same = ProcSelection::new(2, 3);
        $this->assertSame($same, $same->clamp(100, 10));
    }

    public function testAccessorsAndIndex(): void
    {
        $s = ProcSelection::new(-4, -1, -2);
        $this->assertSame([0, 0, 0], [$s->start, $s->selected, $s->lastSelected]);
        $this->assertNull($s->index());
        $s = $s->withStart(10)->withSelected(3)->withLastSelected(2);
        $this->assertSame(12, $s->index());
        $this->assertSame(2, $s->lastSelected);
    }
}
