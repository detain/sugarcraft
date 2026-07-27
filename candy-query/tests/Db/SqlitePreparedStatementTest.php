<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\SqlitePreparedStatement;
use SugarCraft\Query\Db\PreparedStatementInterface;

/**
 * Tests for SqlitePreparedStatement wrapper.
 */
final class SqlitePreparedStatementTest extends TestCase
{
    public function testImplementsPreparedStatementInterface(): void
    {
        $stmt = new SqlitePreparedStatement(null);

        $this->assertInstanceOf(PreparedStatementInterface::class, $stmt);
    }

    public function testExecuteReturnsFalseWhenInnerIsNull(): void
    {
        $stmt = new SqlitePreparedStatement(null);

        $this->assertFalse($stmt->execute());
    }

    public function testExecuteReturnsFalseWithParamsWhenInnerIsNull(): void
    {
        $stmt = new SqlitePreparedStatement(null);

        $this->assertFalse($stmt->execute(['param' => 'value']));
    }

    public function testFetchReturnsFalseWhenInnerIsNull(): void
    {
        $stmt = new SqlitePreparedStatement(null);

        $this->assertFalse($stmt->fetch());
    }

    public function testFetchAllReturnsEmptyArrayWhenInnerIsNull(): void
    {
        $stmt = new SqlitePreparedStatement(null);

        $this->assertSame([], $stmt->fetchAll());
    }

    public function testRowCountReturnsZeroWhenInnerIsNull(): void
    {
        $stmt = new SqlitePreparedStatement(null);

        $this->assertSame(0, $stmt->rowCount());
    }

    public function testCloseCursorReturnsFalseWhenInnerIsNull(): void
    {
        $stmt = new SqlitePreparedStatement(null);

        $this->assertFalse($stmt->closeCursor());
    }
}
