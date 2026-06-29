<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Schema\MysqlSchemaProvider;
use SugarCraft\Query\Schema\PostgresSchemaProvider;
use SugarCraft\Query\Schema\SqliteSchemaProvider;
use PHPUnit\Framework\TestCase;

final class SchemaDropTableTest extends TestCase
{
    /**
     * Fake DatabaseInterface that captures the last exec() SQL string.
     */
    private function makeFakeDb(string $driverName = 'mysql'): object
    {
        $execSql = null;
        return new class($execSql, $driverName) implements DatabaseInterface {
            public ?string $execSql = null;
            private string $driverName;

            public function __construct(?string &$execSql, string $driverName)
            {
                $this->execSql = &$execSql;
                $this->driverName = $driverName;
            }

            public function tables(): array { return []; }
            public function rows(string $table, int $limit = 100): array { return []; }
            public function query(string $sql): array|null { return []; }
            public function lastInsertId(): string|int { return 0; }
            public function quote(string $value): string { return "'" . $value . "'"; }
            public function exec(string $sql): int { $this->execSql = $sql; return 0; }
            public function close(): void {}
            public function serverVersion(): string { return '8.0.33'; }
            public function driverName(): string { return $this->driverName; }
            public function ping(): bool { return true; }
            public function databases(): array { return ['test']; }
            public function prepare(string $sql): ?\SugarCraft\Query\Db\PreparedStatementInterface { return null; }
            public function dsn(): string { return 'mysql:host=localhost'; }
            public function username(): string { return 'root'; }
        };
    }

    // ── MySQL / MariaDB / Percona ─────────────────────────────────────────────

    public function testMysqlDropTableUsesBacktickIdentifier(): void
    {
        $db = $this->makeFakeDb('mysql');
        $provider = new MysqlSchemaProvider($db);
        $provider->dropTable('users');

        $this->assertSame('DROP TABLE IF EXISTS `users`', $db->execSql);
    }

    public function testMysqlDropTableDoublesInternalBacktick(): void
    {
        $db = $this->makeFakeDb('mysql');
        $provider = new MysqlSchemaProvider($db);
        $provider->dropTable('a`b');

        // Internal backtick is doubled; outer quotes are single backtick pair
        $this->assertSame('DROP TABLE IF EXISTS `a``b`', $db->execSql);
    }

    public function testMysqlDropTableWithFlavorMariaDB(): void
    {
        $db = $this->makeFakeDb('mysql');
        $provider = (new MysqlSchemaProvider($db))->withFlavor(Flavor::MariaDB);
        $provider->dropTable('orders');

        // MariaDB also uses backtick quoting
        $this->assertSame('DROP TABLE IF EXISTS `orders`', $db->execSql);
    }

    public function testMysqlDropTableWithFlavorPercona(): void
    {
        $db = $this->makeFakeDb('mysql');
        $provider = (new MysqlSchemaProvider($db))->withFlavor(Flavor::Percona);
        $provider->dropTable('products');

        // Percona also uses backtick quoting
        $this->assertSame('DROP TABLE IF EXISTS `products`', $db->execSql);
    }

    public function testMysqlDropTableNotStringLiteral(): void
    {
        $db = $this->makeFakeDb('mysql');
        $provider = new MysqlSchemaProvider($db);
        $provider->dropTable('users');

        // Must NOT be a single-quoted string literal (that was the bug)
        $this->assertStringNotContainsString("'users'", $db->execSql);
        $this->assertStringNotContainsString("'a`b'", $db->execSql);
    }

    // ── PostgreSQL ────────────────────────────────────────────────────────────

    public function testPostgresDropTableUsesDoubleQuoteIdentifier(): void
    {
        $db = $this->makeFakeDb('pgsql');
        $provider = new PostgresSchemaProvider($db);
        $provider->dropTable('users');

        $this->assertSame('DROP TABLE IF EXISTS "users"', $db->execSql);
    }

    public function testPostgresDropTableDoublesInternalDoubleQuote(): void
    {
        $db = $this->makeFakeDb('pgsql');
        $provider = new PostgresSchemaProvider($db);
        $provider->dropTable('a"b');

        // Internal double-quote is doubled; outer quotes are double-quote pair
        $this->assertSame('DROP TABLE IF EXISTS "a""b"', $db->execSql);
    }

    public function testPostgresDropTableNotStringLiteral(): void
    {
        $db = $this->makeFakeDb('pgsql');
        $provider = new PostgresSchemaProvider($db);
        $provider->dropTable('users');

        // Must NOT be a single-quoted string literal (that was the bug)
        $this->assertStringNotContainsString("'users'", $db->execSql);
    }
}
