<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Explain;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Explain\PostgresExplainProvider;

final class PostgresExplainProviderTest extends TestCase
{
    private DatabaseInterface $db;
    private PostgresExplainProvider $provider;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->provider = new PostgresExplainProvider($this->db);
    }

    public function testExplainExecutesCorrectQuery(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';
        $jsonResult = [
            ['QUERY PLAN' => json_encode([
                [
                    'Plan' => [
                        'Node Type' => 'Seq Scan',
                        'Relation Name' => 'users',
                        'Total Cost' => 0.00,
                        'Plan Rows' => 1,
                    ],
                ],
            ])],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with("EXPLAIN (FORMAT JSON) {$sql}")
            ->willReturn($jsonResult);

        $result = $this->provider->explain($sql);

        $this->assertNotEmpty($result);
    }

    public function testExplainReturnsEmptyArrayForEmptySql(): void
    {
        $result = $this->provider->explain('');

        $this->assertSame([], $result);
    }

    public function testExplainReturnsEmptyArrayWhenQueryReturnsNoRows(): void
    {
        $sql = 'SELECT * FROM users';

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn([]);

        $result = $this->provider->explain($sql);

        $this->assertSame([], $result);
    }

    public function testExplainParsesJsonFormatWithNestedPlans(): void
    {
        $sql = 'SELECT u.name FROM users u JOIN orders o ON u.id = o.user_id';
        $jsonResult = [
            ['QUERY PLAN' => json_encode([
                [
                    'Plan' => [
                        'Node Type' => 'Hash Join',
                        'Total Cost' => 10.50,
                        'Plan Rows' => 100,
                        'Plans' => [
                            [
                                'Node Type' => 'Seq Scan',
                                'Relation Name' => 'users',
                                'Alias' => 'u',
                            ],
                            [
                                'Node Type' => 'Seq Scan',
                                'Relation Name' => 'orders',
                                'Alias' => 'o',
                            ],
                        ],
                    ],
                ],
            ])],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($jsonResult);

        $result = $this->provider->explain($sql);

        $this->assertIsArray($result);
    }

    public function testExplainHandlesActualRowsAndLoops(): void
    {
        $sql = 'SELECT * FROM users';
        $jsonResult = [
            ['QUERY PLAN' => json_encode([
                [
                    'Plan' => [
                        'Node Type' => 'Seq Scan',
                        'Relation Name' => 'users',
                        'Actual Rows' => 1000,
                        'Actual Loops' => 1,
                    ],
                ],
            ])],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->willReturn($jsonResult);

        $result = $this->provider->explain($sql);

        $this->assertIsArray($result);
    }

    public function testGetDriverNameReturnsPgsql(): void
    {
        $this->assertSame('pgsql', $this->provider->getDriverName());
    }
}
