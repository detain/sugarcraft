<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Table;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Widgets\Table\Table;

/**
 * Width-budget pins (W-36 / sugar-widgets-056): the gutters between
 * columns are part of the configured width's budget. A table squeezed
 * narrower than its own separator budget used to emit rows WIDER than
 * the caller's line-wrap guard; every rendered line must now fit the
 * configured width for ANY width from 0 up.
 */
final class WidthBudgetTest extends TestCase
{
    /** @param list<list<string>> $rows @param list<string> $headers */
    private function table(array $headers, array $rows, int $width): Table
    {
        return Table::new($headers, $rows)->withSize($width, 10);
    }

    public static function narrowWidthProvider(): array
    {
        $rows = [['alpha', 'bravo', 'charlie', 'delta', 'echo']];
        return [
            'width 1'                   => [1, $rows],
            'width 2 gutters unpayable' => [2, $rows],
            'width 4 gutters == width'  => [4, $rows],
            'width 5 one cell left'     => [5, $rows],
        ];
    }

    public function testZeroWidthIsAutoNotBudgetZero(): void
    {
        // W-35 law: width 0 means AUTO (unconstrained), not a 0-cell cap.
        $rows = [['alpha', 'bravo', 'charlie', 'delta', 'echo']];
        $this->assertSame(
            Table::new(['A', 'B', 'C', 'D', 'E'], $rows)->view(),
            $this->table(['A', 'B', 'C', 'D', 'E'], $rows, 0)->view(),
            'withSize(0,0) is the natural auto render',
        );
    }

    /**
     * @dataProvider narrowWidthProvider
     */
    public function testEveryLineFitsTheBudget(int $width, array $rows): void
    {
        $view = $this->table(['A', 'B', 'C', 'D', 'E'], $rows, $width)->view();
        foreach (explode("\n", $view) as $line) {
            $this->assertLessThanOrEqual(
                max(0, $width),
                Width::string($line),
                "width {$width}: line escapes the budget: " . var_export($line, true),
            );
        }
    }

    public function testWideCellNeighbourStaysInBudget(): void
    {
        // CJK clusters (2 cells) must not smuggle a line past the cap.
        $view = $this->table(['名前', '説明'], [['アップデート', '秒']], 3)->view();
        foreach (explode("\n", $view) as $line) {
            $this->assertLessThanOrEqual(3, Width::string($line));
        }
    }

    public function testNormalWidthBytesAreUnchanged(): void
    {
        // Regression: a table that can pay for its gutters renders the
        // classic single-space join with auto column widths (the shape
        // every existing golden holds — goldens zero-move).
        $t = Table::new(['A', 'B'], [['x', 'y']])->withSize(20, 10);
        $lines = explode("\n", $t->view());
        // The header carries its default underline style and the body its
        // default faint style; the join is the classic single space,
        // byte-for-byte (goldens zero-move).
        $this->assertSame("\e[4mA B\e[0m", $lines[0]);
        $this->assertSame("\e[2mx y\e[0m", $lines[1]);
    }
}
