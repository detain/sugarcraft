<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\SqliteDatabase;

/**
 * Post-close guards (D-M32, 2026-11-03).
 *
 * close() nulls the PDO handle. Before this family of guards, three of the
 * seven handle readers died with "call to a member function on null" - a
 * raw PHP fatal in the middle of a TUI teardown race. The shipped contract
 * mirrors MysqlDatabase exactly: list readers answer empty, version
 * readers say "unknown", and quote() fails loudly because a silently
 * empty quote would splice nothing-where-a-value-belongs into SQL.
 */
final class SqliteDatabaseClosedTest extends TestCase
{
    private SqliteDatabase $db;

    protected function setUp(): void
    {
        $this->db = SqliteDatabase::open(':memory:');
        $this->db->exec('CREATE TABLE t (id INTEGER)');
        $this->db->close();
    }

    public function testTablesAnswerEmptyListAfterClose(): void
    {
        $this->assertSame([], $this->db->tables());
    }

    public function testRowsAnswerEmptyListAfterClose(): void
    {
        $this->assertSame([], $this->db->rows('t'));
    }

    public function testQueryStillAnswersNullAfterClose(): void
    {
        $this->assertNull($this->db->query('SELECT 1'));
    }

    public function testExecAnswersZeroAfterClose(): void
    {
        $this->assertSame(0, $this->db->exec('SELECT 1'));
    }

    public function testLastInsertIdAnswersZeroAfterClose(): void
    {
        $this->assertSame(0, $this->db->lastInsertId());
    }

    public function testServerVersionAnswersUnknownAfterClose(): void
    {
        $this->assertSame('SQLite version unknown', $this->db->serverVersion());
    }

    public function testQuoteRefusesLoudlyAfterClose(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot quote without connection');
        $this->db->quote("o'brien");
    }

    public function testPingAnswersFalseAfterClose(): void
    {
        $this->assertFalse($this->db->ping());
    }
}
