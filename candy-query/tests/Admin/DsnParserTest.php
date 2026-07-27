<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\DsnParser;

/**
 * Tests for DsnParser DSN value extractor.
 */
final class DsnParserTest extends TestCase
{
    public function testExtractMySqlHost(): void
    {
        $result = DsnParser::extract('mysql:host=localhost;port=3306', 'host');

        $this->assertSame('localhost', $result);
    }

    public function testExtractMySqlPort(): void
    {
        $result = DsnParser::extract('mysql:host=localhost;port=3306', 'port');

        $this->assertSame('3306', $result);
    }

    public function testExtractMySqlWithDbname(): void
    {
        $result = DsnParser::extract('mysql:host=localhost;port=3306;dbname=testdb', 'dbname');

        $this->assertSame('testdb', $result);
    }

    public function testExtractPostgresHost(): void
    {
        $result = DsnParser::extract('pgsql:host=127.0.0.1;port=5432', 'host');

        $this->assertSame('127.0.0.1', $result);
    }

    public function testExtractPostgresPort(): void
    {
        $result = DsnParser::extract('pgsql:host=127.0.0.1;port=5432', 'port');

        $this->assertSame('5432', $result);
    }

    public function testExtractWithEmptyValueReturnsNull(): void
    {
        // Empty value (dbname= with nothing after) - regex doesn't match empty
        $result = DsnParser::extract('mysql:host=localhost;port=3306;dbname=', 'dbname');

        $this->assertNull($result);
    }

    public function testExtractReturnsNullWhenKeyAbsent(): void
    {
        $result = DsnParser::extract('mysql:host=localhost', 'port');

        $this->assertNull($result);
    }

    public function testExtractWithBareDsnWithoutPrefix(): void
    {
        $result = DsnParser::extract('host=localhost;port=3306', 'host');

        $this->assertSame('localhost', $result);
    }

    public function testExtractWithBareDsnWithPort(): void
    {
        $result = DsnParser::extract('host=localhost;port=3306', 'port');

        $this->assertSame('3306', $result);
    }

    public function testExtractWithSpecialCharactersInValue(): void
    {
        $result = DsnParser::extract('mysql:host=localhost;dbname=test_db_123', 'dbname');

        $this->assertSame('test_db_123', $result);
    }

    public function testExtractWithMultipleSemicolons(): void
    {
        $result = DsnParser::extract('mysql:host=localhost;a=b;port=3306;c=d', 'port');

        $this->assertSame('3306', $result);
    }

    public function testExtractMySqlWithUnixSocket(): void
    {
        $result = DsnParser::extract('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=test', 'dbname');

        $this->assertSame('test', $result);
    }

    public function testExtractWithColonInValue(): void
    {
        $result = DsnParser::extract('mysql:host=localhost;password=p@ss:word', 'password');

        $this->assertSame('p@ss:word', $result);
    }
}
