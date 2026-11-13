<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

/**
 * The styleFunc type funnel (audit ST-23, finding 023): string,
 * SugarCraft\CellBuffer\Style and SugarCraft\Style\Style are the accepted
 * vocabulary; everything else throws naming the cell.
 */
final class TableStyleFuncFunnelTest extends TestCase
{
    private function table(int $rows = 1): Table
    {
        $data = [];
        for ($i = 0; $i < $rows; $i++) {
            $data[] = Row::new(RowData::from(['v' => 'r' . $i]));
        }

        return Table::fromColumns([Column::new('v', 'V', 10)])->withRows($data);
    }

    public function testDeclarativeStyleObjectRendersItsAttributes(): void
    {
        $view = $this->table()
            ->withStyleFunc(
                static fn (int $row, int $col, string $value): \SugarCraft\Style\Style =>
                    \SugarCraft\Style\Style::new()->bold()->fg('#ff0000')
            )
            ->view();

        // Canonicalised 24-bit output (the cheat-table parse path): bold
        // plus truecolour red must survive the flattening + re-parse, and
        // the zero colour channels must NOT be eaten as reset digits.
        $this->assertStringContainsString('38;2;255;0;0', $view);
        $this->assertStringContainsString('1;', $view, 'bold attribute rides the emitted SGR');
    }

    public function testEmptyDeclarativeStyleMeansNoStyle(): void
    {
        $blank = $this->table()
            ->withStyleFunc(static fn (): \SugarCraft\Style\Style => \SugarCraft\Style\Style::new())
            ->view();
        $plain = $this->table()->view();

        $this->assertSame($plain, $blank);
    }

    public function testGarbageReturnThrowsNamingTheCell(): void
    {
        $t = $this->table()->withStyleFunc(static fn (int $row, int $col, string $v): int => 42);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('styleFunc(0, 0) must return an ANSI SGR string or a Style object, got int');
        $t->view();
    }

    public function testForgottenReturnThrowsAsNull(): void
    {
        $t = $this->table()->withStyleFunc(static function (int $row, int $col, string $v): void {
        });

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('got null');
        $t->view();
    }

    public function testThrowCarriesCoordinatesOnLaterCells(): void
    {
        $t = $this->table(2)
            ->withStyleFunc(static fn (int $row, int $col): mixed => $row === 1 ? ['not', 'a', 'style'] : '');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('styleFunc(1, 0) must return an ANSI SGR string or a Style object, got array');
        $t->view();
    }

    public function testMultilinePathUsesTheSameFunnel(): void
    {
        $t = $this->table()
            ->withMultilineMode()
            ->withStyleFunc(static fn (): bool => true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('got bool');
        $t->view();
    }

    public function testStringArmStillPassesThrough(): void
    {
        $styled = $this->table()
            ->withStyleFunc(static fn (): string => '31')
            ->view();

        // '31' is canonicalised through the ANSI16->24-bit oracle, not
        // echoed — the string arm passing through means PARSED, honoured.
        $this->assertStringContainsString('205;0;0', $styled);
    }
}
