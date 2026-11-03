<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Db;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Db\Identifier;
use SugarCraft\Sql\Db\Flavor;

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

    /**
     * D-C9 keystone (2026-11-03): a name ending in `\` must NOT swallow the
     * closing backtick. Under MySQL's grammar the backslash is an escape char
     * inside `` ` ``-quoted identifiers, so pre-fix output `` `x\` `` leaves the
     * identifier unterminated and everything after it executes. The fix escapes
     * `\` as `\\`, which the grammar reads back as one literal backslash.
     */
    public function testMysqlQuoteNeutralizesTrailingBackslash(): void
    {
        $result = Identifier::quote(Flavor::MySQL, 'x\\');

        // Exact bytes: `x\\` — backslash doubled, then a clean closing tick.
        $this->assertSame('`x\\\\`', $result);
        // Splice attempt: payload after the swallowed tick never forms a bare
        // closing backtick pair — count must be even around the doubled escape.
        $this->assertStringEndsWith('`', $result);
        $this->assertSame(
            2,
            substr_count($result, '`'),
            'exactly the wrapping pair — no swallowed/swallower tick remains',
        );
    }

    public function testMysqlQuoteDoublesBackslashBeforeEscape(): void
    {
        // 'a\`b' hostile name: backslash-escaped tick and a literal tick.
        $result = Identifier::quote(Flavor::MySQL, 'a\\`b');

        $this->assertSame('`a\\\\``b`', $result);
    }

    public function testMysqlQuoteBackslashAndBacktickByteShape(): void
    {
        // Plain backslash mid-name stays escaped.
        $this->assertSame('`a\\\\b`', Identifier::quote(Flavor::MySQL, 'a\\b'));
        // Double quote arms: backslash is NOT special — plain doubling kept.
        $this->assertSame('"a\\b"', Identifier::quote(Flavor::Postgres, 'a\\b'));
        $this->assertSame('"a\\b"', Identifier::quote(Flavor::Sqlite, 'a\\b'));
    }

    public function testMysqlQuoteLiteralEscapesBackslashChannel(): void
    {
        // A trailing backslash must not eat the closing single quote either.
        $this->assertSame("'x\\\\'", Identifier::quoteLiteral(Flavor::MySQL, 'x\\'));
        $this->assertSame("'o''brien'", Identifier::quoteLiteral(Flavor::MySQL, "o'brien"));
        // PG/SQLite standard literals: quote doubling only, backslash literal.
        $this->assertSame("'x\\'", Identifier::quoteLiteral(Flavor::Postgres, 'x\\'));
        $this->assertSame("'o''brien'", Identifier::quoteLiteral(Flavor::Postgres, "o'brien"));
        $this->assertSame("'x\\'", Identifier::quoteLiteral(Flavor::Sqlite, 'x\\'));
    }
}
