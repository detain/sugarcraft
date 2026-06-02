<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\SqliteDatabase;

/**
 * Tests for the DatabaseInterface contract.
 *
 * Verifies that SqliteDatabase properly implements all interface methods.
 */
final class DatabaseInterfaceTest extends TestCase
{
    private SqliteDatabase $db;

    protected function setUp(): void
    {
        $this->db = SqliteDatabase::open(':memory:');
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
        $this->db->exec("INSERT INTO users VALUES (1, 'alice'), (2, 'bob')");
    }

    protected function tearDown(): void
    {
        $this->db->close();
    }

    public function testTablesReturnsUserTablesAndViews(): void
    {
        $this->db->exec('CREATE VIEW active_users AS SELECT * FROM users WHERE id = 1');
        $tables = $this->db->tables();
        $this->assertContains('users', $tables);
        $this->assertContains('active_users', $tables);
    }

    public function testTablesExcludesSystemTables(): void
    {
        $tables = $this->db->tables();
        foreach ($tables as $table) {
            $this->assertStringStartsNotWith('sqlite_', $table);
        }
    }

    public function testTablesEmptyOnFreshDatabase(): void
    {
        $freshDb = SqliteDatabase::open(':memory:');
        try {
            $this->assertSame([], $freshDb->tables());
        } finally {
            $freshDb->close();
        }
    }

    public function testRowsReturnsAssocArrays(): void
    {
        $rows = $this->db->rows('users');
        $this->assertCount(2, $rows);
        $this->assertSame('alice', $rows[0]['name']);
        $this->assertSame(1, $rows[0]['id']);
    }

    public function testRowsRespectsLimit(): void
    {
        $rows = $this->db->rows('users', limit: 1);
        $this->assertCount(1, $rows);
    }

    public function testQueryReturnsSelectResults(): void
    {
        $rows = $this->db->query('SELECT name FROM users ORDER BY name');
        $this->assertSame([['name' => 'alice'], ['name' => 'bob']], $rows);
    }

    public function testQueryReturnsAffectedRowsForNonSelect(): void
    {
        $rows = $this->db->query('INSERT INTO users VALUES (3, \'charlie\')');
        $this->assertSame([['affected' => 1]], $rows);
    }

    public function testLastInsertId(): void
    {
        $this->db->exec("INSERT INTO users VALUES (NULL, 'charlie')");
        $id = $this->db->lastInsertId();
        $this->assertNotSame('0', (string) $id);
    }

    public function testQuoteEscapesProperly(): void
    {
        $quoted = $this->db->quote("O'Reilly");
        $this->assertStringContainsString("'", $quoted);
    }

    public function testExecReturnsAffectedRows(): void
    {
        $affected = $this->db->exec("INSERT INTO users VALUES (NULL, 'charlie')");
        $this->assertSame(1, $affected);
    }

    public function testCloseSetsPdoToNull(): void
    {
        $this->db->close();
        // After close, ping should return false
        $this->assertFalse($this->db->ping());
    }

    public function testServerVersionReturnsString(): void
    {
        $version = $this->db->serverVersion();
        $this->assertIsString($version);
        $this->assertStringContainsString('SQLite', $version);
    }

    public function testDriverNameReturnsSqlite(): void
    {
        $this->assertSame('sqlite', $this->db->driverName());
    }

    public function testPingReturnsTrueWhenConnected(): void
    {
        $this->assertTrue($this->db->ping());
    }

    public function testPingReturnsFalseAfterClose(): void
    {
        $this->db->close();
        $this->assertFalse($this->db->ping());
    }

    public function testDatabasesReturnsMemoryForInMemoryDb(): void
    {
        $databases = $this->db->databases();
        $this->assertSame(['memory'], $databases);
    }

    public function testImplementsDatabaseInterface(): void
    {
        $this->assertInstanceOf(DatabaseInterface::class, $this->db);
    }
}
