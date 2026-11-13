<?php

declare(strict_types=1);

namespace SugarCraft\Sql;

use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\Schema\SchemaProviderInterface;
use SugarCraft\Sql\Schema\SchemaTable;
use SugarCraft\Sql\Schema\SqliteSchemaProvider;

/**
 * Schema browser with driver-aware schema introspection.
 *
 * Delegates to a driver-specific SchemaProviderInterface implementation
 * based on the configured Flavor.
 */
final class SchemaBrowser
{
    /**
     * @param list<SchemaTable> $tables
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Flavor $flavor,
        private readonly SchemaProviderInterface $provider,
        public readonly array $tables = [],
    ) {}

    /**
     * Create a new SchemaBrowser with automatic provider selection based on flavor.
     */
    public static function new(DatabaseInterface $db, Flavor $flavor): self
    {
        $provider = self::createProvider($db, $flavor);
        return new self($db, $flavor, $provider);
    }

    /**
     * Create the appropriate provider for the given flavor.
     */
    private static function createProvider(DatabaseInterface $db, Flavor $flavor): SchemaProviderInterface
    {
        return match ($flavor) {
            Flavor::Sqlite => new SqliteSchemaProvider($db),
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => new \SugarCraft\Sql\Schema\MysqlSchemaProvider($db),
            Flavor::Postgres => new \SugarCraft\Sql\Schema\PostgresSchemaProvider($db),
        };
    }

    /**
     * Refresh schema for all user tables.
     */
    public function refresh(): self
    {
        // Re-derive the provider from the stored (db, flavor): the browser's
        // identity is its configuration, so a refresh must never depend on a
        // provider instance that arrived through some other constructor call.
        $provider = self::createProvider($this->db, $this->flavor);
        $names = $provider->tables();

        $tables = [];
        foreach ($names as $name) {
            $tables[] = $this->loadTable($provider, $name);
        }

        return new self($this->db, $this->flavor, $provider, $tables);
    }

    private function loadTable(SchemaProviderInterface $provider, string $name): SchemaTable
    {
        return new SchemaTable(
            name: $name,
            columns: $provider->columns($name),
            indexes: $provider->indexes($name),
            foreignKeys: $provider->foreignKeys($name),
        );
    }

    /**
     * Drop a table and return a refreshed browser.
     */
    public function dropTable(string $name): self
    {
        $this->provider->dropTable($name);
        return $this->refresh();
    }
}
