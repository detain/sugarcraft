<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\Flavor;

/**
 * Tests for Flavor enum detection.
 */
final class FlavorTest extends TestCase
{
    public function testDetectMySQLFromVersion(): void
    {
        $flavor = Flavor::detectFromVersionString('8.0.33');

        $this->assertSame(Flavor::MySQL, $flavor);
    }

    public function testDetectMySQLWithFullVersionString(): void
    {
        $flavor = Flavor::detectFromVersionString('8.0.33-npm-1');

        $this->assertSame(Flavor::MySQL, $flavor);
    }

    public function testDetectMariaDBFromVersionComment(): void
    {
        $flavor = Flavor::detectFromVersionString(
            '5.5.5-10.11.4-MariaDB-1:10.11.4+maria~ubu2201',
            '10.11.4-MariaDB-1:10.11.4+maria~ubu2201',
        );

        $this->assertSame(Flavor::MariaDB, $flavor);
    }

    public function testDetectMariaDBFromVersionCommentOnly(): void
    {
        $flavor = Flavor::detectFromVersionString(
            '8.0.33',
            'MySQL Community Server - GPL - MariaDB server: 10.11.4-MariaDB',
        );

        $this->assertSame(Flavor::MariaDB, $flavor);
    }

    public function testDetectPerconaFromVersionComment(): void
    {
        $flavor = Flavor::detectFromVersionString(
            '8.0.33-18',
            'MySQL Community Server - GPL - Percona Server (GPL), Release 18, Revision 9d3f3f9',
        );

        $this->assertSame(Flavor::Percona, $flavor);
    }

    public function testDetectPostgresFromVersion(): void
    {
        $flavor = Flavor::detectFromVersionString('PostgreSQL 16.0');

        $this->assertSame(Flavor::Postgres, $flavor);
    }

    public function testDetectPostgresWithAdditionalInfo(): void
    {
        $flavor = Flavor::detectFromVersionString('PostgreSQL 16.0 on x86_64-pc-linux-gnu');

        $this->assertSame(Flavor::Postgres, $flavor);
    }

    public function testDetectSqliteFallback(): void
    {
        // SQLite serverVersion() returns "SQLite version X.Y.Z"
        $flavor = Flavor::detectFromVersionString('SQLite version 3.41.0');

        $this->assertSame(Flavor::Sqlite, $flavor);
    }

    public function testDetectSqliteFallbackWithUnknown(): void
    {
        $flavor = Flavor::detectFromVersionString('unknown-version');

        $this->assertSame(Flavor::Sqlite, $flavor);
    }

    public function testDetectMySQLWhenNoFlavorMatches(): void
    {
        // MySQL-style version numbers should default to MySQL
        $flavor = Flavor::detectFromVersionString('5.7.42');

        $this->assertSame(Flavor::MySQL, $flavor);
    }

    public function testMariaDBTakesPrecedenceOverMySQL(): void
    {
        // Even though version looks like MySQL, versionComment indicates MariaDB
        $flavor = Flavor::detectFromVersionString(
            '8.0.33',
            'MySQL Community Server - GPL - MariaDB server: 10.6.12-MariaDB',
        );

        $this->assertSame(Flavor::MariaDB, $flavor);
    }

    public function testPerconaTakesPrecedenceOverMySQL(): void
    {
        $flavor = Flavor::detectFromVersionString(
            '8.0.33',
            'MySQL Community Server - GPL - Percona Server',
        );

        $this->assertSame(Flavor::Percona, $flavor);
    }

    public function testMariaDBTakesPrecedenceOverPercona(): void
    {
        // If both MariaDB and Percona are mentioned, MariaDB takes precedence (checked first)
        $flavor = Flavor::detectFromVersionString(
            '8.0.33',
            'MySQL Community Server - GPL - MariaDB server with Percona',
        );

        $this->assertSame(Flavor::MariaDB, $flavor);
    }

    public function testEnumValues(): void
    {
        $this->assertSame('mysql', Flavor::MySQL->value);
        $this->assertSame('mariadb', Flavor::MariaDB->value);
        $this->assertSame('percona', Flavor::Percona->value);
        $this->assertSame('postgres', Flavor::Postgres->value);
        $this->assertSame('sqlite', Flavor::Sqlite->value);
    }

    // ===== detectFromDriver() tests =====

    public function testDetectFromDriverSqlite(): void
    {
        $flavor = Flavor::detectFromDriver('sqlite');

        $this->assertSame(Flavor::Sqlite, $flavor);
    }

    public function testDetectFromDriverPostgres(): void
    {
        $flavor = Flavor::detectFromDriver('pgsql');

        $this->assertSame(Flavor::Postgres, $flavor);
    }

    public function testDetectFromDriverMysql(): void
    {
        $flavor = Flavor::detectFromDriver('mysql', '8.0.33');

        $this->assertSame(Flavor::MySQL, $flavor);
    }

    public function testDetectFromDriverMysqlWithMariaDBVersionComment(): void
    {
        $flavor = Flavor::detectFromDriver(
            'mysql',
            '8.0.33',
            'MySQL Community Server - GPL - MariaDB server: 10.11.4',
        );

        $this->assertSame(Flavor::MariaDB, $flavor);
    }

    public function testDetectFromDriverMysqlWithPerconaVersionComment(): void
    {
        $flavor = Flavor::detectFromDriver(
            'mysql',
            '8.0.33-18',
            'MySQL Community Server - GPL - Percona Server (GPL)',
        );

        $this->assertSame(Flavor::Percona, $flavor);
    }

    public function testDetectFromDriverUnknownDriver(): void
    {
        $flavor = Flavor::detectFromDriver('unknown-driver');

        $this->assertSame(Flavor::Sqlite, $flavor);
    }

    public function testDetectFromDriverWithPostgresVersionString(): void
    {
        // When driver is 'mysql' but version starts with PostgreSQL, detectFromVersionString
        // is called and detects Postgres from the version string
        $flavor = Flavor::detectFromDriver('mysql', 'PostgreSQL 16.0');

        $this->assertSame(Flavor::Postgres, $flavor);
    }

    // ===== SQL-C03: the driver name is the authority (client-003) =====
    //
    // MysqlDatabase::serverVersion() decorates its answer ('MySQL version
    // 8.0.33'). The pre-C03 'mysql' arm handed that to detectFromVersionString,
    // whose anchored /^\d+\.\d+\.\d+/ arm missed the worded form and returned
    // the SQLite fallback — ExplainView then sent EXPLAIN QUERY PLAN to a live
    // MySQL server (1064). These pins keep the docblock promise true.

    public function testDetectFromDriverDecoratedMysqlVersionStaysMysql(): void
    {
        $this->assertSame(
            Flavor::MySQL,
            Flavor::detectFromDriver('mysql', 'MySQL version 8.0.33'),
        );
    }

    public function testDetectFromDriverGarbageOrEmptyMysqlVersionStaysMysql(): void
    {
        $this->assertSame(Flavor::MySQL, Flavor::detectFromDriver('mysql', 'nonsense'));
        $this->assertSame(Flavor::MySQL, Flavor::detectFromDriver('mysql', ''));
        $this->assertSame(
            Flavor::MySQL,
            Flavor::detectFromDriver('mysql', 'MySQL version unknown'),
        );
    }

    public function testDetectFromDriverMysqlRefinesWithinFamilyFromEmbeddedTokens(): void
    {
        $this->assertSame(
            Flavor::MariaDB,
            Flavor::detectFromDriver('mysql', '5.5.5-10.11.4-MariaDB-1:10.11.4+maria~ubu2204'),
        );
        $this->assertSame(
            Flavor::Percona,
            Flavor::detectFromDriver('mysql', '8.0.33-25 Percona Server (GPL)-release'),
        );
    }

    public function testDetectFromDriverRawNumberFormStillRefinesAsBefore(): void
    {
        // The C03 acceptance pins BOTH forms: raw numeric (pre-existing arm)
        // and worded (the repaired one).
        $this->assertSame(Flavor::MySQL, Flavor::detectFromDriver('mysql', '8.0.33'));
        $this->assertSame(Flavor::MySQL, Flavor::detectFromDriver('mysql', 'MySQL version 8.0.33'));
    }
}
