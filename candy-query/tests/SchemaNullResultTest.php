<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Schema\MysqlSchemaProvider;
use SugarCraft\Query\Schema\PostgresSchemaProvider;
use SugarCraft\Query\Schema\SqliteSchemaProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests that schema providers gracefully handle query() returning null
 * (e.g., after a transient connection error) instead of crashing with
 * a TypeError from foreach(null).
 */
final class SchemaNullResultTest extends TestCase
{
    /**
     * Build a fake DatabaseInterface whose query() always returns null.
     * All other methods are no-ops to allow the provider to be instantiated.
     */
    private function makeNullQueryDb(): object
    {
        return new class implements DatabaseInterface {
            public function tables(): array { return []; }
            public function rows(string $table, int $limit = 100): array { return []; }
            public function query(string $sql): array|null { return null; } // The key: always null
            public function lastInsertId(): string|int { return 0; }
            public function quote(string $value): string { return "'" . $value . "'"; }
            public function exec(string $sql): int { return 0; }
            public function close(): void {}
            public function serverVersion(): string { return '8.0.33'; }
            public function driverName(): string { return 'mysql'; }
            public function ping(): bool { return true; }
            public function databases(): array { return ['test']; }
            public function prepare(string $sql): ?\SugarCraft\Query\Db\PreparedStatementInterface { return null; }
            public function dsn(): string { return 'mysql:host=localhost'; }
            public function username(): string { return 'root'; }
        };
    }

    // ── MysqlSchemaProvider ───────────────────────────────────────────────────

    public function testMysqlTablesReturnsEmptyArrayWhenQueryReturnsNull(): void
    {
        $db = $this->makeNullQueryDb();
        $provider = new MysqlSchemaProvider($db);

        // Should return [] instead of throwing TypeError: foreach() on null
        $this->assertSame([], $provider->tables());
    }

    public function testMysqlColumnsReturnsEmptyArrayWhenQueryReturnsNull(): void
    {
        $db = $this->makeNullQueryDb();
        $provider = new MysqlSchemaProvider($db);

        $this->assertSame([], $provider->columns('users'));
    }

    public function testMysqlIndexesReturnsEmptyArrayWhenQueryReturnsNull(): void
    {
        $db = $this->makeNullQueryDb();
        $provider = new MysqlSchemaProvider($db);

        $this->assertSame([], $provider->indexes('users'));
    }

    public function testMysqlForeignKeysReturnsEmptyArrayWhenQueryReturnsNull(): void
    {
        $db = $this->makeNullQueryDb();
        $provider = new MysqlSchemaProvider($db);

        $this->assertSame([], $provider->foreignKeys('users'));
    }

    // ── PostgresSchemaProvider ────────────────────────────────────────────────

    public function testPostgresTablesReturnsEmptyArrayWhenQueryReturnsNull(): void
    {
        $db = $this->makeNullQueryDb();
        $provider = new PostgresSchemaProvider($db);

        $this->assertSame([], $provider->tables());
    }

    public function testPostgresColumnsReturnsEmptyArrayWhenQueryReturnsNull(): void
    {
        $db = $this->makeNullQueryDb();
        $provider = new PostgresSchemaProvider($db);

        $this->assertSame([], $provider->columns('users'));
    }

    public function testPostgresIndexesReturnsEmptyArrayWhenQueryReturnsNull(): void
    {
        $db = $this->makeNullQueryDb();
        $provider = new PostgresSchemaProvider($db);

        $this->assertSame([], $provider->indexes('users'));
    }

    public function testPostgresForeignKeysReturnsEmptyArrayWhenQueryReturnsNull(): void
    {
        $db = $this->makeNullQueryDb();
        $provider = new PostgresSchemaProvider($db);

        $this->assertSame([], $provider->foreignKeys('users'));
    }
}
