<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\ConnectionConfig;
use SugarCraft\Query\Db\ConnectionFactory;
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\SqliteDatabase;

/**
 * Tests for ConnectionFactory.
 */
final class ConnectionFactoryTest extends TestCase
{
    public function testFromDsnParsesMySqlDsn(): void
    {
        $config = ConnectionFactory::fromDsn('mysql://root:secret@localhost:3306/testdb?ssl-mode=require');

        $this->assertSame('mysql', $config->driver);
        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('root', $config->user);
        $this->assertSame('secret', $config->pass);
        $this->assertSame('testdb', $config->dbname);
        $this->assertSame('require', $config->sslMode);
    }

    public function testFromDsnParsesSqliteMemoryDsn(): void
    {
        $config = ConnectionFactory::fromDsn('sqlite://:memory:');

        $this->assertSame('sqlite', $config->driver);
        $this->assertSame('', $config->host);
        $this->assertSame(0, $config->port);
        $this->assertSame('', $config->user);
        $this->assertSame('', $config->pass);
        $this->assertSame(':memory:', $config->dbname);
    }

    public function testFromDsnParsesSqliteFileDsn(): void
    {
        $config = ConnectionFactory::fromDsn('sqlite:///var/data/mydb.sqlite');

        $this->assertSame('sqlite', $config->driver);
        $this->assertSame('/var/data/mydb.sqlite', $config->dbname);
    }

    public function testFromDsnParsesPgsqlDsn(): void
    {
        $config = ConnectionFactory::fromDsn('pgsql://postgres:pgpass@db.example.com:5432/mydb');

        $this->assertSame('pgsql', $config->driver);
        $this->assertSame('db.example.com', $config->host);
        $this->assertSame(5432, $config->port);
        $this->assertSame('postgres', $config->user);
        $this->assertSame('pgpass', $config->pass);
        $this->assertSame('mydb', $config->dbname);
    }

    public function testFromDsnParsesUrlEncodedPassword(): void
    {
        $config = ConnectionFactory::fromDsn('mysql://root:p%40ss%3Dword@localhost:3306/testdb');

        $this->assertSame('p@ss=word', $config->pass);
    }

    public function testFromDsnThrowsOnEmptyDsn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DSN cannot be empty');

        ConnectionFactory::fromDsn('');
    }

    public function testFromDsnThrowsOnMissingSchemeSeparator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid DSN: missing "://" separator');

        ConnectionFactory::fromDsn('mysql:/localhost:3306/testdb');
    }

    public function testFromDsnThrowsOnUnsupportedDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver: oracle');

        ConnectionFactory::fromDsn('oracle://localhost:1521/orcl');
    }

    public function testFromDsnThrowsOnSqliteMissingHostSeparator(): void
    {
        // For non-SQLite DSNs, must have @ separator
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid DSN: missing credentials separator');

        ConnectionFactory::fromDsn('mysql://localhost:3306/testdb');
    }

    public function testFromConfigCreatesSqliteInMemoryDatabase(): void
    {
        $config = ConnectionConfig::create(
            driver: 'sqlite',
            host: '',
            port: 0,
            user: '',
            pass: '',
            dbname: ':memory:',
        );

        $db = ConnectionFactory::fromConfig($config);

        $this->assertInstanceOf(SqliteDatabase::class, $db);
        $this->assertInstanceOf(DatabaseInterface::class, $db);
        $this->assertSame('sqlite', $db->driverName());
    }

    public function testFromConfigCreatesSqliteFileDatabase(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'candy_query_test_') . '.sqlite';
        try {
            $config = ConnectionConfig::create(
                driver: 'sqlite',
                host: '',
                port: 0,
                user: '',
                pass: '',
                dbname: $tmpFile,
            );

            $db = ConnectionFactory::fromConfig($config);

            $this->assertInstanceOf(SqliteDatabase::class, $db);
            $this->assertTrue($db->ping());

            $db->close();
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testFromConfigThrowsOnUnsupportedDriver(): void
    {
        $config = ConnectionConfig::create(
            driver: 'sqlsrv',
            host: 'localhost',
            port: 1433,
            user: 'sa',
            pass: 'secret',
            dbname: 'testdb',
            sslMode: 'prefer',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver: sqlsrv');

        ConnectionFactory::fromConfig($config);
    }

    public function testFromArgvWithDsnFlag(): void
    {
        $argv = ['program', '--dsn=sqlite://:memory:'];

        $db = ConnectionFactory::fromArgv($argv);

        $this->assertInstanceOf(SqliteDatabase::class, $db);
        $this->assertSame('sqlite', $db->driverName());
    }

    public function testFromArgvWithIndividualFlags(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'candy_query_test_') . '.sqlite';
        try {
            $argv = [
                'program',
                '--driver=sqlite',
                '--db=' . $tmpFile,
            ];

            $db = ConnectionFactory::fromArgv($argv);

            $this->assertInstanceOf(SqliteDatabase::class, $db);
            $this->assertTrue($db->ping());

            $db->close();
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    public function testFromArgvWithFullIndividualFlags(): void
    {
        $argv = [
            'program',
            '--driver=sqlsrv',
            '--host=localhost',
            '--port=1433',
            '--user=sa',
            '--pass=secret',
            '--db=mydb',
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver: sqlsrv');

        ConnectionFactory::fromArgv($argv);
    }

    public function testFromArgvThrowsOnMissingDriver(): void
    {
        $argv = ['program', '--db=mydb'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required arguments');

        ConnectionFactory::fromArgv($argv);
    }

    public function testFromArgvThrowsOnMissingDb(): void
    {
        $argv = ['program', '--driver=sqlite'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required arguments');

        ConnectionFactory::fromArgv($argv);
    }

    public function testFromArgvIgnoresNonDashDashArguments(): void
    {
        $argv = ['program', 'somearg', '--driver=sqlite', '--db=:memory:', 'anotherarg'];

        $db = ConnectionFactory::fromArgv($argv);

        $this->assertInstanceOf(SqliteDatabase::class, $db);
    }
}
