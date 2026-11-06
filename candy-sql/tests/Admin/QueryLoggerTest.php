<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\QueryLogger;

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

    public function testDoubleQuotedCredentialFormsAreRedacted(): void
    {
        // MySQL accepts "..." string literals by default, so every shipped
        // pattern must redact its double-quoted analogue as well.
        $cases = [
            'CREATE USER bob IDENTIFIED BY "dqsecret0"',
            'ALTER USER bob IDENTIFIED WITH caching_sha2_password BY "dqsecret1"',
            'SET PASSWORD FOR bob = PASSWORD("dqsecret2")',
            'CREATE ROLE app WITH LOGIN PASSWORD "dqsecret3"',
        ];
        foreach ($cases as $sql) {
            QueryLogger::log('query', $sql, 0);
        }

        $entries = QueryLogger::getEntries();
        foreach ($cases as $i => $sql) {
            $this->assertStringNotContainsString('dqsecret' . $i, $entries[$i]['sql']);
            $this->assertStringContainsString("'***'", $entries[$i]['sql']);
        }
    }

    public function testDoubleQuotedOrdinaryIdentifiersAreNotOverRedacted(): void
    {
        // The quote classes must only fire in credential positions; a doubled
        // word elsewhere in the statement passes through untouched.
        $sql = 'SELECT "pass" FROM cfg WHERE name = "password_style"';
        QueryLogger::log('query', $sql, 1);

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
