<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Db\Identifier;
use SugarCraft\Query\Db\Flavor;

/**
 * Tests for Identifier SQL quoting.
 */
final class IdentifierTest extends TestCase
{
    public function testQuoteMySQLPlainTable(): void
    {
        $result = Identifier::quote(Flavor::MySQL, 'users');

        $this->assertSame('`users`', $result);
    }

    public function testQuoteMariaDBPlainTable(): void
    {
        $result = Identifier::quote(Flavor::MariaDB, 'products');

        $this->assertSame('`products`', $result);
    }

    public function testQuotePerconaPlainTable(): void
    {
        $result = Identifier::quote(Flavor::Percona, 'orders');

        $this->assertSame('`orders`', $result);
    }

    public function testQuoteMySQLTableWithBacktick(): void
    {
        $result = Identifier::quote(Flavor::MySQL, 'table`name');

        $this->assertSame('`table``name`', $result);
    }

    public function testQuoteMariaDBTableWithBacktick(): void
    {
        $result = Identifier::quote(Flavor::MariaDB, 'my`table');

        $this->assertSame('`my``table`', $result);
    }

    public function testQuotePerconaTableWithBacktick(): void
    {
        $result = Identifier::quote(Flavor::Percona, 'nested``tick');

        $this->assertSame('`nested````tick`', $result);
    }

    public function testQuoteMySQLEmptyString(): void
    {
        $result = Identifier::quote(Flavor::MySQL, '');

        $this->assertSame('``', $result);
    }

    public function testQuoteMySQLWithSpaces(): void
    {
        $result = Identifier::quote(Flavor::MySQL, 'table name');

        $this->assertSame('`table name`', $result);
    }

    public function testQuotePostgresPlainTable(): void
    {
        $result = Identifier::quote(Flavor::Postgres, 'users');

        $this->assertSame('"users"', $result);
    }

    public function testQuotePostgresTableWithDoubleQuote(): void
    {
        $result = Identifier::quote(Flavor::Postgres, 'table"name');

        $this->assertSame('"table""name"', $result);
    }

    public function testQuotePostgresEmptyString(): void
    {
        $result = Identifier::quote(Flavor::Postgres, '');

        $this->assertSame('""', $result);
    }

    public function testQuotePostgresWithSpaces(): void
    {
        $result = Identifier::quote(Flavor::Postgres, 'table name');

        $this->assertSame('"table name"', $result);
    }

    public function testQuoteSqlitePlainTable(): void
    {
        $result = Identifier::quote(Flavor::Sqlite, 'users');

        $this->assertSame('"users"', $result);
    }

    public function testQuoteSqliteTableWithDoubleQuote(): void
    {
        $result = Identifier::quote(Flavor::Sqlite, 'my"table');

        $this->assertSame('"my""table"', $result);
    }

    public function testQuoteSqliteEmptyString(): void
    {
        $result = Identifier::quote(Flavor::Sqlite, '');

        $this->assertSame('""', $result);
    }

    public function testQuoteSqliteWithSpaces(): void
    {
        $result = Identifier::quote(Flavor::Sqlite, 'table name');

        $this->assertSame('"table name"', $result);
    }
}
