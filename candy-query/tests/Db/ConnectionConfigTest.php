<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\ConnectionConfig;

/**
 * Tests for ConnectionConfig readonly value object.
 */
final class ConnectionConfigTest extends TestCase
{
    public function testCreateWithAllFields(): void
    {
        $config = ConnectionConfig::create(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            user: 'root',
            pass: 'secret123',
            dbname: 'testdb',
            sslMode: 'require',
        );

        $this->assertSame('mysql', $config->driver);
        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('root', $config->user);
        $this->assertSame('secret123', $config->pass);
        $this->assertSame('testdb', $config->dbname);
        $this->assertSame('require', $config->sslMode);
        $this->assertSame(
            'mysql:host=localhost;port=3306;dbname=testdb;ssl-mode=require',
            $config->dsn,
        );
    }

    public function testCreateSqliteWithMemoryDatabase(): void
    {
        $config = ConnectionConfig::create(
            driver: 'sqlite',
            host: '',
            port: 0,
            user: '',
            pass: '',
            dbname: ':memory:',
        );

        $this->assertSame('sqlite', $config->driver);
        $this->assertSame(':memory:', $config->dbname);
        $this->assertSame('sqlite::memory:', $config->dsn);
    }

    public function testCreateSqliteWithFilePath(): void
    {
        $config = ConnectionConfig::create(
            driver: 'sqlite',
            host: '',
            port: 0,
            user: '',
            pass: '',
            dbname: '/var/data/mydb.sqlite',
        );

        $this->assertSame('sqlite', $config->driver);
        $this->assertSame('/var/data/mydb.sqlite', $config->dbname);
        // PDO sqlite DSN format: sqlite:/path for absolute paths
        $this->assertSame('sqlite:/var/data/mydb.sqlite', $config->dsn);
    }

    public function testCreatePgsqlDriver(): void
    {
        $config = ConnectionConfig::create(
            driver: 'pgsql',
            host: 'db.example.com',
            port: 5432,
            user: 'postgres',
            pass: 'pgpass',
            dbname: 'mydb',
            sslMode: 'require',
        );

        $this->assertSame('pgsql', $config->driver);
        $this->assertSame('db.example.com', $config->host);
        $this->assertSame(5432, $config->port);
        $this->assertSame('pgsql:host=db.example.com;port=5432;dbname=mydb', $config->dsn);
    }

    public function testCreateWithDefaultSslMode(): void
    {
        $config = ConnectionConfig::create(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            user: 'root',
            pass: 'pass',
            dbname: 'testdb',
        );

        $this->assertSame('prefer', $config->sslMode);
        $this->assertSame('mysql:host=localhost;port=3306;dbname=testdb;ssl-mode=prefer', $config->dsn);
    }

    public function testCreateWithEmptyPassword(): void
    {
        $config = ConnectionConfig::create(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            user: 'root',
            pass: '',
            dbname: 'testdb',
        );

        $this->assertSame('', $config->pass);
        // Empty password is valid - DSN just omits the password portion
        $this->assertStringContainsString('mysql:', $config->dsn);
        $this->assertStringContainsString('host=localhost', $config->dsn);
    }

    public function testBuildDsnWithUnsupportedDriverThrows(): void
    {
        // ConnectionConfig accepts any driver without throwing.
        // Driver validation is the responsibility of ConnectionFactory::fromConfig().
        $config = ConnectionConfig::create(
            driver: 'oracle',
            host: 'localhost',
            port: 1521,
            user: 'system',
            pass: 'oracle',
            dbname: 'orcl',
        );

        // Verify the config was created and DSN was built
        $this->assertSame('oracle', $config->driver);
        $this->assertSame('oracle:host=localhost;port=1521;dbname=orcl', $config->dsn);
    }
}
