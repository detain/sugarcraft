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
        foreach (KeyTable::bindings() as $binding) {
            $this->assertStringContainsString('`' . $binding . '`', $table, "binding {$binding} missing from the generated table");
        }
        $this->assertSame(count(KeyTable::keyRows()) + 2, substr_count($table, "\n"), 'one table row per KeyTable key row');
    }
}
