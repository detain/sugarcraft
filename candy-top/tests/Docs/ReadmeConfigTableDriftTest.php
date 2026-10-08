<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Docs;

use SugarCraft\Top\Config\Schema;

/**
 * README's config-key table is {@see Schema}'s persisted roster — name,
 * type, default, value law, options-menu place and config.conf
 * description. An option added, removed, re-defaulted or re-described
 * without the README edit goes red here.
 */
final class ReadmeConfigTableDriftTest extends ReadmeDriftTestCase
{
    public function testReadmeConfigTableMatchesSchema(): void
    {
        $this->assertReadmeBlock('config', ReadmeTables::configTable());
    }

    public function testTableListsExactlyThePersistedOptionsInFileOrder(): void
    {
        preg_match_all('/^\| `([a-z0-9_]+)` \| (?:bool|int|string) \|/m', ReadmeTables::configTable(), $m);
        $this->assertSame(Schema::persistedNames(), $m[1]);
    }

    public function testRuntimeOnlyStateIsNotDocumentedAsAConfigKey(): void
    {
        $table = ReadmeTables::configTable();
        foreach (array_diff(array_keys(Schema::options()), Schema::persistedNames()) as $runtime) {
            $this->assertStringNotContainsString('| `' . $runtime . '` |', $table, "{$runtime} is runtime state, never written to config.conf");
        }
    }
}
