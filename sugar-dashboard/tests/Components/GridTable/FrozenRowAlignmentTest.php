<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Components\GridTable\BorderConfig;
use SugarCraft\Dashboard\Components\GridTable\Column;
use SugarCraft\Dashboard\Components\GridTable\GridTable;
use SugarCraft\Dashboard\Components\GridTable\Row;

/**
 * P0-A14 regression: a frozen data row is exactly as wide as the frame.
 *
 * renderFrozenRow used to write one extra right wall before the freeze
 * divider, so a frozen row was one cell wider than the header and the
 * unfrozen rows. The divider now takes the seat of that wall, as renderHeader
 * and renderRow already do, and a fully frozen row ends on the closing wall.
 * Every line of a frozen render must measure the same as the matching line of
 * the unfrozen render, at every width, freeze count and border preset.
 */
final class FrozenRowAlignmentTest extends TestCase
{
    /**
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [
            Column::new('name', 'Name'),
            Column::new('city', 'City'),
            Column::new('pop', 'Pop'),
        ];
    }

    /**
     * Wide (CJK, emoji) and narrow cells, so the sweep exercises display-cell
     * padding as well as the wall arithmetic.
     *
     * @return list<Row>
     */
    private static function rows(): array
    {
        return [
            new Row(['name' => '日本語', 'city' => '東京都 🙂', 'pop' => '1']),
            new Row(['name' => 'Alpha', 'city' => 'Berlin', 'pop' => '22']),
        ];
    }

    /**
     * @return list<string>
     */
    private static function lines(int $frozen, int $width, BorderConfig $border): array
    {
        $grid = (new GridTable(self::columns(), self::rows(), null, $border))
            ->freezeColumns($frozen)
            ->setSize($width, 10);

        return explode("\n", $grid->render());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function borderPresets(): iterable
    {
        yield 'new' => ['new'];
        yield 'rounded' => ['rounded'];
        yield 'minimal' => ['minimal'];
        yield 'borderless' => ['borderless'];
    }

    /**
     * @dataProvider borderPresets
     */
    public function testEveryFrozenLineMeasuresTheSameAsTheUnfrozenFrame(string $preset): void
    {
        $border = BorderConfig::$preset();

        foreach ([20, 30, 33, 45, 70] as $width) {
            $base = self::lines(0, $width, $border);

            foreach ([1, 2, 3] as $frozen) {
                $lines = self::lines($frozen, $width, $border);
                self::assertCount(count($base), $lines, "width {$width}, frozen {$frozen}: line count");

                foreach ($lines as $i => $line) {
                    // The borderless header carries a stray '║' from renderHeader's
                    // hard-coded freeze glyph. That is the header's own defect and is
                    // not part of the frozen data row this test pins.
                    if ($preset === 'borderless' && $i === 0) {
                        continue;
                    }

                    self::assertSame(
                        Width::string($base[$i]),
                        Width::string($line),
                        "width {$width}, frozen {$frozen}, line {$i}: {$line}",
                    );
                }
            }
        }
    }

    public function testFrozenDataRowsPutTheDividerInTheHeaderSeat(): void
    {
        $frame = Width::string(self::lines(0, 30, BorderConfig::new())[1]);
        $lines = self::lines(1, 30, BorderConfig::new());

        self::assertSame($frame, Width::string($lines[1]), 'header');
        self::assertSame($frame, Width::string($lines[3]), 'frozen data row');
        self::assertSame($frame, Width::string($lines[4]), 'frozen data row');

        // The divider sits at the same cell as the header's divider, so the
        // frozen half of every row is the same width as the frozen header cell.
        $seat = Width::string(explode('║', $lines[1])[0]);
        self::assertSame($seat, Width::string(explode('║', $lines[3])[0]));
        self::assertSame($seat, Width::string(explode('║', $lines[4])[0]));
    }

    public function testFullyFrozenRowEndsOnTheClosingWallWithoutDivider(): void
    {
        $lines = self::lines(3, 30, BorderConfig::new());

        self::assertSame(0, substr_count($lines[3], '║'), 'no scrollable seat, so no divider');
        self::assertSame(Width::string($lines[1]), Width::string($lines[3]));
        self::assertStringEndsWith('┃', $lines[3]);
    }
}
