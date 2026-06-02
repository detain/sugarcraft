<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Explain;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Explain\ExplainProviderInterface;
use SugarCraft\Query\Explain\SqliteExplainProvider;
use SugarCraft\Query\Explain\MysqlExplainProvider;
use SugarCraft\Query\Explain\PostgresExplainProvider;

final class ExplainProviderInterfaceTest extends TestCase
{
    public function testSqliteExplainProviderImplementsInterface(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $provider = new SqliteExplainProvider($db);

        $this->assertInstanceOf(ExplainProviderInterface::class, $provider);
    }

    public function testMysqlExplainProviderImplementsInterface(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $provider = new MysqlExplainProvider($db);

        $this->assertInstanceOf(ExplainProviderInterface::class, $provider);
    }

    public function testPostgresExplainProviderImplementsInterface(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $provider = new PostgresExplainProvider($db);

        $this->assertInstanceOf(ExplainProviderInterface::class, $provider);
    }

    public function testSqliteProviderReturnsCorrectDriverName(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $provider = new SqliteExplainProvider($db);

        $this->assertSame('sqlite', $provider->getDriverName());
    }

    public function testMysqlProviderReturnsCorrectDriverName(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $provider = new MysqlExplainProvider($db);

        $this->assertSame('mysql', $provider->getDriverName());
    }

    public function testPostgresProviderReturnsCorrectDriverName(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $provider = new PostgresExplainProvider($db);

        $this->assertSame('pgsql', $provider->getDriverName());
    }

    public function testSqliteProviderReturnsEmptyArrayForEmptySql(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $db->method('query')->willReturn([]);
        $provider = new SqliteExplainProvider($db);

        $result = $provider->explain('');

        $this->assertSame([], $result);
    }

    public function testMysqlProviderReturnsEmptyArrayForEmptySql(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $provider = new MysqlExplainProvider($db);

        $result = $provider->explain('');

        $this->assertSame([], $result);
    }

    public function testPostgresProviderReturnsEmptyArrayForEmptySql(): void
    {
        $db = $this->createMock(\SugarCraft\Query\Db\DatabaseInterface::class);
        $provider = new PostgresExplainProvider($db);

        $result = $provider->explain('');

        $this->assertSame([], $result);
    }
}
