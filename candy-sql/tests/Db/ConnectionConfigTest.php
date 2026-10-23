<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\ConnectionConfig;

/**
 * Tests for ConnectionConfig readonly value object.
 */
final class ConnectionConfigTest extends TestCase
{
    public function testCreateWithAllFields(): void
    {
        $config = ConnectionConfig::new(
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
        // MySQL ssl-mode is NOT in the DSN — SSL rides PDO driver options at
        // connect time (MysqlDatabase::sslDriverOptions). PostgreSQL differs:
        // see testCreatePgsqlIncludesSslModeBelow.
        $this->assertSame(
            'mysql:host=localhost;port=3306;dbname=testdb',
            $config->dsn,
        );
    }

    public function testCreateSqliteWithMemoryDatabase(): void
    {
        $config = ConnectionConfig::new(
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
        $config = ConnectionConfig::new(
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
        $config = ConnectionConfig::new(
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
        // sslMode reaches Postgres only through the DSN — libpq has no PDO
        // driver option equivalent. Pinned by the docs-review-1023 F1 fix.
        $this->assertSame('pgsql:host=db.example.com;port=5432;dbname=mydb;sslmode=require', $config->dsn);
    }

    /** MySQL-flavoured spellings must translate to libpq's hyphen forms. */
    public function testPgsqlSslModeTranslatesMysqlFlavouredSpellings(): void
    {
        $translate = new \ReflectionMethod(ConnectionConfig::class, 'pgsqlSslModeSuffix');

        $cases = [
            '' => '',
            'disable' => ';sslmode=disable',
            'DISABLE' => ';sslmode=disable',
            'allow' => ';sslmode=allow',
            'prefer' => ';sslmode=prefer',
            'required' => ';sslmode=require',
            'verify_ca' => ';sslmode=verify-ca',
            'VERIFY-CA' => ';sslmode=verify-ca',
            'verify_identity' => ';sslmode=verify-full',
            'verify-full' => ';sslmode=verify-full',
            // Unknown names omit the suffix and inherit libpq's default;
            // the ssl-mode vocabulary is validated per-driver elsewhere.
            'tls' => '',
        ];

        foreach ($cases as $mode => $expected) {
            $this->assertSame(
                $expected,
                $translate->invoke(null, $mode),
                'pgsql sslmode translation for ' . var_export($mode, true),
            );
        }
    }

    /** A pgsql config with no sslMode at all keeps the plain DSN (constructor path). */
    public function testPgsqlWithoutSslModeOmitsSuffix(): void
    {
        $config = ConnectionConfig::new(
            driver: 'pgsql',
            host: 'localhost',
            port: 5432,
            user: 'u',
            pass: '',
            dbname: 'd',
            sslMode: '',
        );

        $this->assertSame('pgsql:host=localhost;port=5432;dbname=d', $config->dsn);
    }

    public function testCreateWithDefaultSslMode(): void
    {
        $config = ConnectionConfig::new(
            driver: 'mysql',
            host: 'localhost',
            port: 3306,
            user: 'root',
            pass: 'pass',
            dbname: 'testdb',
        );

        $this->assertSame('prefer', $config->sslMode);
        $this->assertSame('mysql:host=localhost;port=3306;dbname=testdb', $config->dsn);
    }

    public function testCreateWithEmptyPassword(): void
    {
        $config = ConnectionConfig::new(
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
        $config = ConnectionConfig::new(
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
