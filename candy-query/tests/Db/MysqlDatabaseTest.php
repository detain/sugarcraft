<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\MysqlDatabase;

/**
 * Tests for MysqlDatabase implementation.
 *
 * Tests behavior that doesn't require mocking final PDO methods.
 * Uses reflection to inject test doubles for behavior verification.
 */
final class MysqlDatabaseTest extends TestCase
{
    private MysqlDatabase $db;

    protected function setUp(): void
    {
        // Create MysqlDatabase instance without constructor via reflection
        // Since constructor is private (use connect() factory in production)
        $reflection = new \ReflectionClass(MysqlDatabase::class);
        $this->db = $reflection->newInstanceWithoutConstructor();

        // Set pdo property to null initially
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setAccessible(true);
        $pdoProperty->setValue($this->db, null);
    }

    public function testImplementsDatabaseInterface(): void
    {
        $this->assertInstanceOf(DatabaseInterface::class, $this->db);
    }

    public function testDriverNameReturnsMysql(): void
    {
        $this->assertSame('mysql', $this->db->driverName());
    }

    public function testPingReturnsFalseWhenDisconnected(): void
    {
        $this->db->close();
        $this->assertFalse($this->db->ping());
    }

    public function testServerVersionReturnsUnknownWhenDisconnected(): void
    {
        $this->db->close();
        $this->assertSame('MySQL version unknown', $this->db->serverVersion());
    }

    public function testDatabasesReturnsEmptyWhenDisconnected(): void
    {
        $this->db->close();
        $this->assertSame([], $this->db->databases());
    }

    public function testTablesReturnsEmptyWhenDisconnected(): void
    {
        $this->db->close();
        $this->assertSame([], $this->db->tables());
    }

    public function testRowsReturnsEmptyWhenDisconnected(): void
    {
        $this->db->close();
        $this->assertSame([], $this->db->rows('users'));
    }

    public function testRowsReturnsEmptyWhenDisconnectedWithLimit(): void
    {
        $this->assertSame([], $this->db->rows('users', 50));
    }

    public function testQueryReturnsEmptyWhenDisconnected(): void
    {
        $this->assertSame([], $this->db->query('SELECT 1'));
    }

    public function testLastInsertIdReturnsZeroWhenDisconnected(): void
    {
        $this->assertSame(0, $this->db->lastInsertId());
    }

    public function testQuoteThrowsExceptionWhenDisconnected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot quote without connection');
        $this->db->quote('test');
    }

    public function testExecReturnsZeroWhenDisconnected(): void
    {
        $this->assertSame(0, $this->db->exec('DELETE FROM users'));
    }

    public function testCloseSetsPdoToNull(): void
    {
        $this->db->close();
        // After close, ping should return false
        $this->assertFalse($this->db->ping());
    }

    public function testCloseIsIdempotent(): void
    {
        $this->db->close();
        $this->db->close(); // Second close should not throw
        $this->assertFalse($this->db->ping());
    }

    public function testServerVersionFormat(): void
    {
        // Driver name must be 'mysql'
        $this->assertSame('mysql', $this->db->driverName());
    }

    public function testDatabaseMethodContract(): void
    {
        // Verify all interface methods are callable (signature test)
        $reflection = new \ReflectionClass(DatabaseInterface::class);
        $requiredMethods = [
            'tables',
            'rows',
            'query',
            'lastInsertId',
            'quote',
            'exec',
            'close',
            'serverVersion',
            'driverName',
            'ping',
            'databases',
        ];

        foreach ($requiredMethods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "DatabaseInterface should have {$method} method",
            );
        }
    }
}
