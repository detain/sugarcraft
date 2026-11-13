<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\Column;
use SugarCraft\Table\Row;
use SugarCraft\Table\RowData;
use SugarCraft\Table\Table;

/**
 * OG-1 / ST-02: the camelCase house-API rename keeps every upstream
 * PascalCase spelling alive through PHP's case-insensitive method dispatch
 * (same mechanism as sugar-datepicker dp-02).
 */
final class ApiAliasTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: array<int, mixed>}> */
    public static function aliasProvider(): array
    {
        $pairs = [
            ['SelectNext', []], ['SelectPrevious', []], ['SelectPage', [1]], ['NextPage', []],
            ['PreviousPage', []], ['SortBy', ['n', false]], ['ClearSort', []], ['Filter', ['n', 'a']],
            ['ClearFilter', ['n']], ['ClearAllFilters', []], ['ClearSearch', []], ['Columns', []],
            ['Rows', []], ['CurrentRow', []], ['CurrentRowData', []], ['SelectedRow', []],
            ['TotalRows', []], ['TotalPages', []], ['SelectedIndex', []], ['CurrentPage', []],
            ['PageSize', []], ['PageFooter', []], ['RowsFooter', []], ['View', []],
        ];
        $out = [];
        foreach ($pairs as [$pascal, $args]) {
            $out[$pascal] = [$pascal, \lcfirst($pascal), $args];
        }

        return $out;
    }

    public function testNoPublicMethodIsDeclaredPascalCase(): void
    {
        foreach ((new \ReflectionClass(Table::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            self::assertMatchesRegularExpression('/^[a-z_]/', $m->getName(), "{$m->getName()}() must be declared camelCase");
        }
    }

    /** @param array<int, mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('aliasProvider')]
    public function testDeprecatedSpellingResolvesToCamelTwin(string $pascal, string $camel, array $args): void
    {
        $ref = new \ReflectionClass(Table::class);
        self::assertTrue($ref->hasMethod($camel));
        self::assertSame($camel, $ref->getMethod($pascal)->getName());

        $a = $this->base()->$pascal(...$args);
        $b = $this->base()->$camel(...$args);
        self::assertSame($this->fingerprint($b), $this->fingerprint($a));
    }

    private function fingerprint(mixed $r): string
    {
        if ($r instanceof Table) {
            return $r->view() . '|' . $r->selectedIndex() . '|' . $r->currentPage() . '|' . $r->totalRows();
        }

        return \serialize($r);
    }

    private function base(): Table
    {
        return Table::fromColumns([Column::new('n', 'Name', 8)])
            ->withRows([Row::new(RowData::from(['n' => 'alpha'])), Row::new(RowData::from(['n' => 'beta']))]);
    }
}
