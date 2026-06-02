<?php

declare(strict_types=1);

namespace SugarCraft\Query;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Schema\SchemaProviderInterface;
use SugarCraft\Query\Schema\SchemaTable;
use SugarCraft\Query\Schema\SqliteSchemaProvider;

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
    public static function create(DatabaseInterface $db, Flavor $flavor): self
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
            Flavor::MySQL, Flavor::MariaDB, Flavor::Percona => new \SugarCraft\Query\Schema\MysqlSchemaProvider($db),
            Flavor::Postgres => new \SugarCraft\Query\Schema\PostgresSchemaProvider($db),
        };
    }

    /**
     * Refresh schema for all user tables.
     */
    public function refresh(): self
    {
        $names = $this->provider->tables();

        $tables = [];
        foreach ($names as $name) {
            $tables[] = $this->loadTable($name);
        }

        return new self($this->db, $this->flavor, $this->provider, $tables);
    }

    private function loadTable(string $name): SchemaTable
    {
        return new SchemaTable(
            name: $name,
            columns: $this->provider->columns($name),
            indexes: $this->provider->indexes($name),
            foreignKeys: $this->provider->foreignKeys($name),
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
