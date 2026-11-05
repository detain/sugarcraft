<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Docs;

use SugarCraft\Top\Input\KeyTable;

/**
 * README's key-binding table is {@see KeyTable} — the same roster the help
 * overlay and `bin/candy-top --help` print, itself pinned against the
 * handlers by KeyTableTest. A key added, dropped or reworded in the table
 * without the README edit goes red here.
 */
final class ReadmeKeyTableDriftTest extends ReadmeDriftTestCase
{
    public function testReadmeKeyTableMatchesKeyTable(): void
    {
        $this->assertReadmeBlock('keys', ReadmeTables::keyTable());
    }

    public function testEveryDocumentedBindingAppearsInTheTable(): void
    {
        $table = ReadmeTables::keyTable();
        $candyTopOnly = 0;
        foreach (KeyTable::keyRows() as $row) {
            if ($row->candyTopOnly) {
                ++$candyTopOnly;
                continue; // Its binding list is candy-top's own and is not printed as btop keys; the marker leg below covers it.
            }
            foreach ($row->bindings as $binding) {
                $this->assertStringContainsString('`' . $binding . '`', $table, "binding {$binding} missing from the generated table");
            }
        }
        $this->assertSame(3, $candyTopOnly, 'exactly the VM dashboard and ipmi rows are candy-top-only');
        $this->assertSame(3, substr_count($table, '— (candy-top only)'), 'each candy-top-only row is marked in the btop column');
        $this->assertSame(count(KeyTable::keyRows()) + 2, substr_count($table, "\n"), 'one table row per KeyTable key row');
    }
}
