<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

/**
 * ST-57 (finding 072): the centered label seats — empty-state message and
 * page footer — share the data cells' announced-clip law (ST-17): an
 * over-wide label truncates with a visible '…' instead of vanishing
 * silently, and the line keeps the exact frame width either way.
 */
final class TableAnnouncedLabelTest extends TestCase
{
    /** @return list<string> */
    private static function lines(string $view): array
    {
        return explode("\n", $view);
    }

    public function testNarrowEmptyStateLabelAnnouncesItsCut(): void
    {
        $view = Table::fromColumns([Column::new('v', 'V', 8)])
            ->withRows([])
            ->withShowHeader(false)
            ->View();

        $this->assertStringContainsString('No data', $view, '8 columns fit the whole label');

        $narrow = Table::fromColumns([Column::new('v', 'V', 3)])
            ->withRows([])
            ->withShowHeader(false)
            ->View();

        $this->assertStringContainsString('No…', $narrow);
        $this->assertStringNotContainsString('No data', $narrow);
        // Uniform width law: every frame line is exactly the solved width.
        foreach (self::lines($narrow) as $line) {
            $this->assertSame(5, Width::string(Ansi::strip($line)));
        }
    }

    public function testNarrowFooterLabelAnnouncesItsCut(): void
    {
        $rows = [];
        for ($i = 0; $i < 9; $i++) {
            $rows[] = Row::new(RowData::from(['v' => (string) $i]));
        }

        // 'Page 2 of 3' (11 columns) inside a 6-column content area.
        $view = Table::fromColumns([Column::new('v', 'V', 6)])
            ->withRows($rows)
            ->withPageSize(3)
            ->withPage(1)
            ->View();

        $this->assertStringContainsString('…', $view);
        $this->assertStringNotContainsString('Page 2 of 3', $view);

        $wide = Table::fromColumns([Column::new('v', 'V', 20)])
            ->withRows($rows)
            ->withPageSize(3)
            ->withPage(1)
            ->View();
        $this->assertStringContainsString('Page 2 of 3', $wide);
    }
}
