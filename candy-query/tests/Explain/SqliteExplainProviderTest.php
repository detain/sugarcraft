<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Explain;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Explain\SqliteExplainProvider;

final class SqliteExplainProviderTest extends TestCase
{
    private DatabaseInterface $db;
    private SqliteExplainProvider $provider;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->provider = new SqliteExplainProvider($this->db);
    }

    public function testExplainExecutesCorrectQuery(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';
        $expectedResult = [
            ['detail' => 'SEARCH TABLE users USING INTEGER PRIMARY KEY (rowid=?)'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with("EXPLAIN QUERY PLAN {$sql}")
            ->willReturn($expectedResult);

        $result = $this->provider->explain($sql);

        $this->assertSame($expectedResult, $result);
    }

    public function testExplainReturnsEmptyArrayForEmptySql(): void
    {
        $result = $this->provider->explain('');

        $this->assertSame([], $result);
    }

    public function testExplainFiltersEmptyDetailRows(): void
    {
        $sql = 'SELECT * FROM users';
        $rawResult = [
            ['detail' => 'SCAN TABLE users'],
            ['detail' => ''],
            ['detail' => 'USE TEMP B-TREE FOR RIGHT PART OF CROSS JOIN'],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with("EXPLAIN QUERY PLAN {$sql}")
            ->willReturn($rawResult);

        $result = $this->provider->explain($sql);

        $this->assertCount(2, $result);
    }

    public function testExplainReturnsEmptyArrayWhenQueryReturnsNoRows(): void
    {
        $sql = 'SELECT * FROM nonexistent';

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn([]);

        $result = $this->provider->explain($sql);

        $this->assertSame([], $result);
    }

    public function testGetDriverNameReturnsSqlite(): void
    {
        $this->assertSame('sqlite', $this->provider->getDriverName());
    }
}
