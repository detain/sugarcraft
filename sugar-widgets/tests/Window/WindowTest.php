<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Window;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Window\Window;
use SugarCraft\Widgets\Window\WindowSlice;

final class WindowTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int, int, int}>
     *   count, selected, size => expected start, expected end
     */
    public static function cases(): array
    {
        return [
            'empty list'                       => [0, 0, 5, 0, 0],
            'empty list, selection set'        => [0, 3, 5, 0, 0],
            'negative count'                   => [-4, 0, 5, 0, 0],
            'size zero'                        => [10, 5, 0, 0, 0],
            'negative size'                    => [10, 5, -3, 0, 0],
            'size larger than count'           => [3, 1, 10, 0, 3],
            'size equal to count'              => [5, 4, 5, 0, 5],
            'selection at first row'           => [20, 0, 5, 0, 5],
            'selection at last row'            => [20, 19, 5, 15, 20],
            'selection near start'             => [20, 1, 5, 0, 5],
            'selection near end'               => [20, 18, 5, 15, 20],
            'odd size centers the selection'   => [20, 10, 5, 8, 13],
            'even size puts one more row below' => [20, 10, 4, 9, 13],
            'size one is the selection alone'  => [20, 7, 1, 7, 8],
            'negative selection clamps to 0'   => [20, -6, 5, 0, 5],
            'selection past end clamps to last' => [20, 99, 5, 15, 20],
            'single row list'                  => [1, 0, 5, 0, 1],
            'everything out of range'          => [-1, -1, -1, 0, 0],
        ];
    }

    #[DataProvider('cases')]
    public function testAroundClampsToTheExpectedSlice(
        int $count,
        int $selected,
        int $size,
        int $start,
        int $end,
    ): void {
        $slice = Window::around($count, $selected, $size);

        self::assertSame($start, $slice->start());
        self::assertSame($end, $slice->end());
        self::assertSame($end - $start, $slice->length());
    }

    public function testEveryInRangeSelectionIsVisibleAndTheWindowIsFull(): void
    {
        foreach ([1, 2, 3, 7, 12] as $count) {
            foreach ([1, 2, 3, 4, 5, 15] as $size) {
                $expectedLength = min($size, $count);
                $previousStart = 0;
                for ($selected = 0; $selected < $count; $selected++) {
                    $slice = Window::around($count, $selected, $size);
                    $label = "count={$count} size={$size} selected={$selected}";

                    self::assertSame($expectedLength, $slice->length(), $label);
                    self::assertGreaterThanOrEqual($slice->start(), $selected, $label);
                    self::assertLessThan($slice->end(), $selected, $label);
                    self::assertGreaterThanOrEqual(0, $slice->start(), $label);
                    self::assertLessThanOrEqual($count, $slice->end(), $label);
                    // Moving the selection down never scrolls the window up.
                    self::assertGreaterThanOrEqual($previousStart, $slice->start(), $label);
                    $previousStart = $slice->start();
                }
            }
        }
    }

    public function testTheSliceSelectsTheVisibleRowsWithArraySlice(): void
    {
        $rows = range(0, 9);
        $slice = Window::around(count($rows), 6, 3);

        self::assertSame([5, 6, 7], array_slice($rows, $slice->start(), $slice->length()));
    }

    public function testAroundIsPureAndReturnsEqualValuesForEqualInputs(): void
    {
        self::assertEquals(Window::around(50, 20, 7), Window::around(50, 20, 7));
        self::assertNotSame(Window::around(50, 20, 7), Window::around(50, 20, 7));
    }

    public function testWindowCannotBeInstantiated(): void
    {
        $constructor = (new \ReflectionClass(Window::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    public function testAroundReturnsAReadonlyValue(): void
    {
        $reflection = new \ReflectionClass(WindowSlice::class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }
}
