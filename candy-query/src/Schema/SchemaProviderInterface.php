<?php

declare(strict_types=1);

namespace SugarCraft\Query\Schema;

use SugarCraft\Query\Db\Flavor;

/**
 * Interface for driver-aware schema introspection.
 *
 * Each implementation provides database-specific queries for
 * browsing tables, columns, indexes, and foreign keys.
 */
interface SchemaProviderInterface
{
    /**
     * List all user tables (non-system tables).
     *
     * @return list<string>
     */
    public function tables(): array;

    /**
     * List all columns for a given table.
     *
     * @param string $table Table name
     * @return list<SchemaColumn>
     */
    public function columns(string $table): array;

    /**
     * List all indexes for a given table.
     *
     * @param string $table Table name
     * @return list<SchemaIndex>
     */
    public function indexes(string $table): array;

    /**
     * List all foreign keys for a given table.
     *
     * @param string $table Table name
     * @return list<SchemaForeignKey>
     */
    public function foreignKeys(string $table): array;

    /**
     * Drop a table by name.
     *
     * @param string $table Table name
     * @return void
     */
    public function dropTable(string $table): void;

    /**
     * Return a new instance with the specified flavor.
     *
     * @param Flavor $flavor Database flavor
     * @return self
     */
    public function withFlavor(Flavor $flavor): self;
}
