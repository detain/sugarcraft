<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\PdoPreparedStatement;
use SugarCraft\Sql\Db\PreparedStatementInterface;

/**
 * Tests for PdoPreparedStatement wrapper.
 */
final class PdoPreparedStatementTest extends TestCase
{
    public function testImplementsPreparedStatementInterface(): void
    {
        $stmt = new PdoPreparedStatement(null);

        $this->assertInstanceOf(PreparedStatementInterface::class, $stmt);
    }

    public function testExecuteReturnsFalseWhenInnerIsNull(): void
    {
        $stmt = new PdoPreparedStatement(null);

        $this->assertFalse($stmt->execute());
    }

    public function testExecuteReturnsFalseWithParamsWhenInnerIsNull(): void
    {
        $stmt = new PdoPreparedStatement(null);

        $this->assertFalse($stmt->execute(['param' => 'value']));
    }

    public function testFetchReturnsFalseWhenInnerIsNull(): void
    {
        $stmt = new PdoPreparedStatement(null);

        $this->assertFalse($stmt->fetch());
    }

    public function testFetchAllReturnsEmptyArrayWhenInnerIsNull(): void
    {
        $stmt = new PdoPreparedStatement(null);

        $this->assertSame([], $stmt->fetchAll());
    }

    public function testRowCountReturnsZeroWhenInnerIsNull(): void
    {
        $stmt = new PdoPreparedStatement(null);

        $this->assertSame(0, $stmt->rowCount());
    }

    public function testCloseCursorReturnsFalseWhenInnerIsNull(): void
    {
        $stmt = new PdoPreparedStatement(null);

        $this->assertFalse($stmt->closeCursor());
    }
}
