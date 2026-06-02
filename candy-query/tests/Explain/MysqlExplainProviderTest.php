<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Explain;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Explain\MysqlExplainProvider;

final class MysqlExplainProviderTest extends TestCase
{
    private DatabaseInterface $db;
    private MysqlExplainProvider $provider;

    protected function setUp(): void
    {
        $this->db = $this->createMock(DatabaseInterface::class);
        $this->provider = new MysqlExplainProvider($this->db);
    }

    public function testExplainExecutesCorrectQuery(): void
    {
        $sql = 'SELECT * FROM users WHERE id = 1';
        $jsonResult = [
            ['EXPLAIN' => json_encode([
                'query_block' => [
                    'select_id' => 1,
                    'cost_info' => ['evaluated_cost' => '0.35'],
                ],
            ])],
        ];

        $this->db->expects($this->once())
            ->method('query')
            ->with("EXPLAIN FORMAT=JSON {$sql}")
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

    public function testExplainParsesJsonFormatCorrectly(): void
    {
        $sql = 'SELECT u.name FROM users u JOIN orders o ON u.id = o.user_id';
        $jsonResult = [
            ['EXPLAIN' => json_encode([
                'query_block' => [
                    'select_id' => 1,
                    'cost_info' => ['evaluated_cost' => '1.50'],
                    'nested_loop' => [
                        [
                            'table' => 'u',
                            'access_type' => 'const',
                            'key' => 'PRIMARY',
                        ],
                        [
                            'table' => 'o',
                            'access_type' => 'ref',
                            'key' => 'user_id',
                            'key_part' => ['user_id'],
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

    public function testGetDriverNameReturnsMysql(): void
    {
        $this->assertSame('mysql', $this->provider->getDriverName());
    }
}
