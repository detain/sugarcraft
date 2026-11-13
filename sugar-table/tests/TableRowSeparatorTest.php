<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, Row, RowData, Table, WrapMode};

/** OG-5 / ST-71: withRowSeparator draws header-style rules between data rows. */
final class TableRowSeparatorTest extends TestCase
{
    private function table(int $n = 3): Table
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = Row::new(RowData::from(['a' => "r{$i}"]));
        }

        return Table::fromColumns([Column::new('a', 'A', 6)->withWrapMode(WrapMode::Character)])
            ->withRows($rows)->withSelectable(false);
    }

    public function testRulesSitBetweenRowsOnly(): void
    {
        $lines = \explode("\n", $this->table()->withRowSeparator()->view());
        $this->assertSame(['┌──────┐', '│     A│', '│──────│', '│    r0│', '│──────│', '│    r1│', '│──────│', '│    r2│', '└──────┘'], \array_map(static fn (string $l): string => \preg_replace('/\e\[[0-9;]*m/', '', $l), $lines));
    }

    public function testDefaultAndSingleRowHaveNoSeparator(): void
    {
        $this->assertSame($this->table()->view(), $this->table()->withRowSeparator(false)->view());
        $this->assertSame($this->table(1)->view(), $this->table(1)->withRowSeparator()->view());
    }

    public function testSeparatorsCountInRenderedLinesAndMultiline(): void
    {
        $t = $this->table();
        $this->assertSame($t->renderedLineCount() + 2, $t->withRowSeparator()->renderedLineCount());
        $m = $t->withMultilineMode(true)->withRowSeparator();
        $this->assertSame(\substr_count($m->view(), "\n") + 1, $m->renderedLineCount());
    }

    public function testBorderlessDrawsTheRule(): void
    {
        $view = $this->table(2)->withBorderless()->withRowSeparator()->view();
        $this->assertStringContainsString('──────', $view);
    }
}
