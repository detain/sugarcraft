<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\ConnectionConfig;
use SugarCraft\Sql\Db\ConnectionFactory;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\SqliteDatabase;

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

    public function testFromDsnParsesPasswordWithAtSymbol(): void
    {
        // Password containing @ should be URL-encoded
        $config = ConnectionFactory::fromDsn('mysql://root:p%40ss@host:3306/testdb');

        $this->assertSame('p@ss', $config->pass);
        $this->assertSame('root', $config->user);
        $this->assertSame('host', $config->host);
    }

    public function testFromDsnParsesPasswordWithColon(): void
    {
        // Password containing : should be URL-encoded
        $config = ConnectionFactory::fromDsn('mysql://root:p%3Ass%3Aword@host:3306/testdb');

        $this->assertSame('p:ss:word', $config->pass);
        $this->assertSame('root', $config->user);
    }

    public function testFromDsnParsesPasswordlessUser(): void
    {
        // Passwordless: mysql://user@host/db (no :pass part)
        $config = ConnectionFactory::fromDsn('mysql://root@localhost/testdb');

        $this->assertSame('root', $config->user);
        $this->assertSame('', $config->pass);
        $this->assertSame('localhost', $config->host);
        $this->assertSame('testdb', $config->dbname);
    }

    public function testFromDsnParsesPasswordlessUserWithPort(): void
    {
        $config = ConnectionFactory::fromDsn('mysql://root@localhost:3306/testdb');

        $this->assertSame('root', $config->user);
        $this->assertSame('', $config->pass);
        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
    }

    public function testFromDsnParsesIpv6Host(): void
    {
        // IPv6 addresses are wrapped in brackets in URLs
        $config = ConnectionFactory::fromDsn('mysql://u:p@[::1]:3306/db');

        $this->assertSame('u', $config->user);
        $this->assertSame('p', $config->pass);
        $this->assertSame('::1', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('db', $config->dbname);
    }

    public function testFromDsnParsesIpv6Localhost(): void
    {
        $config = ConnectionFactory::fromDsn('mysql://root@[::1]/testdb');

        $this->assertSame('::1', $config->host);
        $this->assertSame(3306, $config->port, 'a portless mysql DSN defaults to 3306, not 0');
        $this->assertSame('testdb', $config->dbname);
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

    public function testFromDsnAllowsDsnWithoutCredentials(): void
    {
        // DSN without @ separator — parse_url() handles this as no user/pass (valid for local connections)
        $config = ConnectionFactory::fromDsn('mysql://localhost:3306/testdb');

        $this->assertSame('mysql', $config->driver);
        $this->assertSame('localhost', $config->host);
        $this->assertSame(3306, $config->port);
        $this->assertSame('', $config->user);
        $this->assertSame('', $config->pass);
        $this->assertSame('testdb', $config->dbname);
    }

    public function testFromConfigCreatesSqliteInMemoryDatabase(): void
    {
        $config = ConnectionConfig::new(
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
        // SQL-O15 migration: the appended '.sqlite' never existed (tempnam
        // names the un-suffixed file), so this leg previously PASSED by
        // exercising PDO's create-on-open default — the very behaviour the
        // no-create law now refuses. Touch the file: the leg's real subject
        // is dialing an EXISTING SQLite file.
        touch($tmpFile);
        try {
            $config = ConnectionConfig::new(
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
        $config = ConnectionConfig::new(
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
        touch($tmpFile); // SQL-O15 migration — see testFromConfigCreatesSqliteFileDatabase
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

    public function testFromArgvNamesTheBogusDriverNotMissingArguments(): void
    {
        // client-021: --driver=bogus (with --db present) must name the real
        // fault — the value — instead of misreporting a missing argument.
        $argv = ['program', '--driver=bogus', '--db=mydb'];

        try {
            ConnectionFactory::fromArgv($argv);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('bogus', $e->getMessage());
            $this->assertStringContainsString('sqlite, mysql, pgsql', $e->getMessage());
        }
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

    public function testFromDsnDefaultsMissingMysqlPortTo3306(): void
    {
        // Regression: a portless DSN used to yield port 0, which PDO tolerates
        // (libmysqlclient falls back to 3306) but the async react/mysql driver
        // connects to literally :0 and is refused.
        $config = ConnectionFactory::fromDsn('mysql://replication_user:secret@mysqlcluster1/mydb');

        $this->assertSame(3306, $config->port);
        $this->assertStringContainsString('port=3306', $config->dsn);
    }

    public function testFromDsnDefaultsMissingPostgresPortTo5432(): void
    {
        $config = ConnectionFactory::fromDsn('pgsql://u:p@dbhost/app');

        $this->assertSame(5432, $config->port);
    }

    public function testFromDsnHonoursAnExplicitNonStandardPort(): void
    {
        $config = ConnectionFactory::fromDsn('mysql://u:p@managed.example.com:25060/app');

        $this->assertSame(25060, $config->port);
    }

    public function testPgsqlDsnAcceptsTheNativeSslmodeSpelling(): void
    {
        // mn-4 (2026-11-03): a libpq-style ?sslmode=… DSN used to silently
        // downgrade to 'prefer'; both spellings are honoured now, with the
        // documented hyphen form winning when both appear.
        $config = ConnectionFactory::fromDsn('pgsql://u:p@dbhost/app?sslmode=require');
        $this->assertSame('require', $config->sslMode);
        $this->assertStringContainsString('sslmode=verify-full', $config->dsn);

        $both = ConnectionFactory::fromDsn('pgsql://u:p@dbhost/app?ssl-mode=disable&sslmode=require');
        $this->assertSame('disable', $both->sslMode);
    }

    /**
     * SQL-O15 (once-019): the SQLite no-create law must guard every dial,
     * not just the bare-path form. A path-shaped DSN that names a missing
     * file used to fall through to PDO's create-on-open default and mint an
     * empty database (losing every write at exit); it now answers the same
     * named refusal SqliteDatabase::open() gives, WITHOUT creating the file.
     */
    public function testSqliteFileDsnRefusesNonexistentPathWithoutCreatingIt(): void
    {
        $missing = sys_get_temp_dir() . '/candy-sql-o15-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->assertFalse(is_file($missing));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('no such SQLite file: ' . $missing);
            ConnectionFactory::fromArgv(['program', '--dsn=sqlite://' . $missing]);
        } finally {
            // The refusal must be a real door: nothing may be minted.
            if (is_file($missing)) {
                @unlink($missing);
                $this->fail('the refused DSN still created the SQLite file');
            }
        }
    }

    public function testSqliteFlagRouteRefusesMissingFileWithTheBarePathSentence(): void
    {
        $missing = sys_get_temp_dir() . '/candy-sql-o15-flag-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->assertFalse(is_file($missing));

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('no such SQLite file: ' . $missing);
            ConnectionFactory::fromArgv(['program', '--driver=sqlite', '--db=' . $missing]);
        } finally {
            if (is_file($missing)) {
                @unlink($missing);
            }
        }
    }

    public function testSqliteDsnToAnExistingFileStillDials(): void
    {
        // Non-vacuity: the refusal keys on EXISTENCE, not on the DSN shape.
        $tmp = tempnam(sys_get_temp_dir(), 'candy_sql_o15_ok_') . '.sqlite';
        touch($tmp);
        try {
            $db = ConnectionFactory::fromArgv(['program', '--dsn=sqlite://' . $tmp]);
            $this->assertInstanceOf(SqliteDatabase::class, $db);
            $this->assertTrue($db->ping());
            $db->close();
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }
}
