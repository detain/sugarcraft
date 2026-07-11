<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\QueryLogger;

/**
 * QueryLogger retains queries in memory and renders them on the Debug pane, so
 * credential-bearing statements must be redacted before they are stored — a
 * plaintext password must never reach the log or the display.
 */
final class QueryLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        QueryLogger::clear();
    }

    protected function tearDown(): void
    {
        QueryLogger::clear();
    }

    public function testIdentifiedByPasswordIsRedactedInStoredSql(): void
    {
        QueryLogger::log('query', "CREATE USER 'bob'@'%' IDENTIFIED BY 's3cr3t!'", 0);

        $entry = QueryLogger::getEntries()[0];
        $this->assertStringNotContainsString('s3cr3t!', $entry['sql']);
        $this->assertStringContainsString("IDENTIFIED BY '***'", $entry['sql']);
    }

    public function testErrorTextIsRedactedToo(): void
    {
        // Driver errors frequently echo the offending statement verbatim.
        QueryLogger::log('error', 'CREATE USER x', 0, "syntax error near IDENTIFIED BY 'leaky'");

        $entry = QueryLogger::getEntries()[0];
        $this->assertNotNull($entry['error']);
        $this->assertStringNotContainsString('leaky', $entry['error']);
        $this->assertStringContainsString("IDENTIFIED BY '***'", $entry['error']);
    }

    public function testPasswordFunctionFormIsRedacted(): void
    {
        QueryLogger::log('query', "SET PASSWORD FOR bob = PASSWORD('topsecret')", 0);

        $entry = QueryLogger::getEntries()[0];
        $this->assertStringNotContainsString('topsecret', $entry['sql']);
        $this->assertStringContainsString("PASSWORD('***')", $entry['sql']);
    }

    public function testPostgresPasswordClauseIsRedacted(): void
    {
        QueryLogger::log('query', "CREATE ROLE app WITH LOGIN PASSWORD 'pgpass'", 0);

        $this->assertStringNotContainsString('pgpass', QueryLogger::getEntries()[0]['sql']);
    }

    public function testIdentifiedWithPluginByIsRedacted(): void
    {
        QueryLogger::log('query', "ALTER USER bob IDENTIFIED WITH caching_sha2_password BY 'hunter2'", 0);

        $this->assertStringNotContainsString('hunter2', QueryLogger::getEntries()[0]['sql']);
    }

    public function testOrdinarySqlIsUntouched(): void
    {
        $sql = 'SELECT id, name FROM users WHERE id = 5';
        QueryLogger::log('query', $sql, 3);

        $this->assertSame($sql, QueryLogger::getEntries()[0]['sql']);
    }

    public function testRedactedSecretDoesNotLeakThroughDisplayLines(): void
    {
        QueryLogger::log('query', "CREATE USER 'bob'@'%' IDENTIFIED BY 's3cr3t!'", 0);

        $lines = QueryLogger::getDisplayLines();
        $this->assertNotEmpty($lines);
        $this->assertStringNotContainsString('s3cr3t!', $lines[0]);
    }
}
