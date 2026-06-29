<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\Db\Flavor;
use SugarCraft\Query\Db\Identifier;
use PHPUnit\Framework\TestCase;

final class IdentifierTest extends TestCase
{
    // ── MySQL / MariaDB / Percona (backtick) ─────────────────────────────────

    public function testQuoteMySQLPlainTable(): void
    {
        $this->assertSame('`users`', Identifier::quote(Flavor::MySQL, 'users'));
    }

    public function testQuoteMariaDBPlainTable(): void
    {
        $this->assertSame('`users`', Identifier::quote(Flavor::MariaDB, 'users'));
    }

    public function testQuotePerconaPlainTable(): void
    {
        $this->assertSame('`users`', Identifier::quote(Flavor::Percona, 'users'));
    }

    public function testQuoteMySQLTableWithBacktick(): void
    {
        // Internal backtick is doubled, not ended
        $this->assertSame('`a``b`', Identifier::quote(Flavor::MySQL, 'a`b'));
    }

    public function testQuoteMariaDBTableWithBacktick(): void
    {
        $this->assertSame('`a``b`', Identifier::quote(Flavor::MariaDB, 'a`b'));
    }

    public function testQuotePerconaTableWithBacktick(): void
    {
        $this->assertSame('`a``b`', Identifier::quote(Flavor::Percona, 'a`b'));
    }

    public function testQuoteMySQLEmptyString(): void
    {
        // str_replace on empty string returns empty; result is just the quote pair
        $this->assertSame('``', Identifier::quote(Flavor::MySQL, ''));
    }

    public function testQuoteMySQLWithSpaces(): void
    {
        $this->assertSame('`my table`', Identifier::quote(Flavor::MySQL, 'my table'));
    }

    // ── PostgreSQL (double-quote) ────────────────────────────────────────────

    public function testQuotePostgresPlainTable(): void
    {
        $this->assertSame('"users"', Identifier::quote(Flavor::Postgres, 'users'));
    }

    public function testQuotePostgresTableWithDoubleQuote(): void
    {
        // Internal double-quote is doubled, not ended
        $this->assertSame('"a""b"', Identifier::quote(Flavor::Postgres, 'a"b'));
    }

    public function testQuotePostgresEmptyString(): void
    {
        // str_replace on empty string returns empty; result is just the quote pair
        $this->assertSame('""', Identifier::quote(Flavor::Postgres, ''));
    }

    public function testQuotePostgresWithSpaces(): void
    {
        $this->assertSame('"my table"', Identifier::quote(Flavor::Postgres, 'my table'));
    }

    // ── SQLite (double-quote) ─────────────────────────────────────────────────

    public function testQuoteSqlitePlainTable(): void
    {
        $this->assertSame('"users"', Identifier::quote(Flavor::Sqlite, 'users'));
    }

    public function testQuoteSqliteTableWithDoubleQuote(): void
    {
        // Internal double-quote is doubled, not ended
        $this->assertSame('"a""b"', Identifier::quote(Flavor::Sqlite, 'a"b'));
    }

    public function testQuoteSqliteEmptyString(): void
    {
        $this->assertSame('""', Identifier::quote(Flavor::Sqlite, ''));
    }

    public function testQuoteSqliteWithSpaces(): void
    {
        $this->assertSame('"my table"', Identifier::quote(Flavor::Sqlite, 'my table'));
    }
}
