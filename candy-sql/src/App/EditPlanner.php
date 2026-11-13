<?php

declare(strict_types=1);

namespace SugarCraft\Sql\App;

use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Db\PreviewQuery;
use SugarCraft\Sql\Schema\SchemaColumn;

/**
 * The refusal ladder in front of every in-grid write (SQL-O03): can THIS row
 * be addressed safely, and may THIS cell be overwritten from the grid?
 *
 * Pure functions over a table's schema columns and the preview row on
 * screen, so the whole decision table is unit-testable without a TUI. Every
 * refusal is the name of a `Lang` key under `edit.refuse_*`, never prose —
 * the caller renders it in the user's locale.
 */
final class EditPlanner
{
    /**
     * Primary-key columns of the table, in column order.
     *
     * @param list<SchemaColumn> $schema
     * @return list<string>
     */
    public static function pkColumns(array $schema): array
    {
        $pk = [];
        foreach ($schema as $col) {
            if ($col->primaryKey) {
                $pk[] = $col->name;
            }
        }

        return $pk;
    }

    /**
     * The full primary key of $row, or the refusal that explains why the row
     * cannot be addressed (no key at all; a key value that is missing, NULL,
     * or the binary-preview placeholder instead of the real bytes).
     *
     * @param list<SchemaColumn>  $schema
     * @param array<string,mixed> $row
     * @return array{key: array<string, mixed>}|array{refusal: string}
     */
    public static function key(Flavor $flavor, array $schema, array $row): array
    {
        $pk = self::pkColumns($schema);
        if ($pk === []) {
            return ['refusal' => 'edit.refuse_no_pk'];
        }
        $types = [];
        foreach ($schema as $col) {
            $types[$col->name] = $col->type;
        }
        $key = [];
        foreach ($pk as $name) {
            if (!array_key_exists($name, $row) || $row[$name] === null
                || PreviewQuery::elides($flavor, $types[$name] ?? '')) {
                return ['refusal' => 'edit.refuse_pk_hidden'];
            }
            $key[$name] = $row[$name];
        }

        return ['key' => $key];
    }

    /**
     * Refusal for overwriting $column from the grid, or null when allowed:
     * the column must exist in the schema and must not be one the browse
     * preview shows as a size placeholder instead of its bytes.
     *
     * @param list<SchemaColumn> $schema
     */
    public static function cellRefusal(Flavor $flavor, array $schema, string $column): ?string
    {
        foreach ($schema as $col) {
            if ($col->name === $column) {
                return PreviewQuery::elides($flavor, $col->type) ? 'edit.refuse_binary' : null;
            }
        }

        return 'edit.refuse_schema';
    }
}
