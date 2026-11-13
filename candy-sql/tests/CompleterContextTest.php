<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Completer;
use SugarCraft\Sql\Db\Flavor;
use SugarCraft\Sql\SqlTokenizer;

/**
 * SQL-O04 schema leg: the pure context classifier. `|` marks the caret in
 * every fixture so the pins read as the editor shows them.
 */
final class CompleterContextTest extends TestCase
{
    private const TABLES = ['users', 'orders', 'order items'];

    /** @return list<string> */
    private static function columnsOf(string $table): array
    {
        return match ($table) {
            'users' => ['id', 'name', 'email'],
            'orders' => ['id', 'user_id', 'total'],
            'order items' => ['order_id', 'sku'],
            default => [],
        };
    }

    /** @return array{0: string, 1: list<string>, 2: string} kind, candidates, prefix */
    private function at(string $marked, ?array $tables = null, ?Flavor $flavor = null): array
    {
        $caret = mb_strpos($marked, '|');
        $this->assertIsInt($caret);
        $sql = str_replace('|', '', $marked);
        $c = Completer::complete(
            $sql,
            $caret,
            $tables ?? self::TABLES,
            static fn (string $t): array => self::columnsOf($t),
            $flavor,
        );
        return [$c->kind, $c->candidates, $c->prefix];
    }

    public function testTablesAfterFromJoinUpdateIntoAndTable(): void
    {
        foreach (['SELECT * FROM |', 'SELECT * FROM users JOIN |', 'UPDATE |', 'INSERT INTO |', 'DROP TABLE |'] as $sql) {
            [$kind, $cands] = $this->at($sql);
            $this->assertSame('table', $kind, $sql);
            $this->assertSame(['users', 'orders', '"order items"'], $cands, $sql);
        }
    }

    public function testTablePrefixFilterIsCaseInsensitive(): void
    {
        [$kind, $cands, $prefix] = $this->at('SELECT * FROM US|');
        $this->assertSame(['table', ['users'], 'US'], [$kind, $cands, $prefix]);
    }

    public function testCommaInFromClauseStaysTableContext(): void
    {
        [$kind] = $this->at('SELECT * FROM users u, |');
        $this->assertSame('table', $kind);
    }

    public function testUnqualifiedColumnsComeOnlyFromTablesInTheStatement(): void
    {
        [$kind, $cands] = $this->at('SELECT | FROM users');
        $this->assertSame('column', $kind);
        $this->assertSame(['id', 'name', 'email'], $cands);

        [, $cands] = $this->at('SELECT | FROM users JOIN orders ON 1=1');
        $this->assertSame(['id', 'name', 'email', 'user_id', 'total'], $cands, 'deduped, statement tables only');
        $this->assertNotContains('sku', $cands);
    }

    public function testWhereOrderByAndSetPositionsAreColumnContexts(): void
    {
        foreach (['SELECT 1 FROM users WHERE |', 'SELECT 1 FROM users WHERE id = 1 AND |', 'SELECT 1 FROM users ORDER BY |',
            'SELECT 1 FROM users GROUP BY id, |', 'UPDATE users SET name = 1, |', 'SELECT count(|) FROM users',
            'SELECT 1 FROM users WHERE id = |'] as $sql) {
            [$kind, $cands] = $this->at($sql);
            $this->assertSame('column', $kind, $sql);
            $this->assertContains('email', $cands, $sql);
        }
    }

    public function testColumnPrefixFiltersAndPrefersColumnsOverKeywords(): void
    {
        [$kind, $cands] = $this->at('SELECT na| FROM users');
        $this->assertSame('column', $kind);
        $this->assertSame(['name'], $cands, 'NATURAL must not dilute a column hit');
    }

    public function testColumnContextFallsBackToKeywordsWhenNoColumnMatches(): void
    {
        [$kind, $cands] = $this->at('SELECT dist| FROM users');
        $this->assertSame('keyword', $kind);
        $this->assertSame(['DISTINCT'], $cands);
    }

    public function testAliasResolutionWithAndWithoutAs(): void
    {
        [$kind, $cands, $prefix] = $this->at('SELECT u.| FROM users u');
        $this->assertSame(['qualified', ['id', 'name', 'email'], ''], [$kind, $cands, $prefix]);

        [, $cands] = $this->at('SELECT o.to| FROM users AS u JOIN orders AS o ON o.user_id = u.id');
        $this->assertSame(['total'], $cands);

        [, $cands] = $this->at('SELECT x.| FROM users u JOIN orders x ON 1=1');
        $this->assertSame(['id', 'user_id', 'total'], $cands);
    }

    public function testQualifiedByTableNameAndUnknownQualifier(): void
    {
        [$kind, $cands] = $this->at('SELECT users.| FROM users');
        $this->assertSame(['qualified', ['id', 'name', 'email']], [$kind, $cands]);

        // A schema table not in the statement still answers by its own name.
        [, $cands] = $this->at('SELECT orders.t| FROM users');
        $this->assertSame(['total'], $cands);

        [$kind, $cands] = $this->at('SELECT nope.| FROM users');
        $this->assertSame(['qualified', []], [$kind, $cands]);
    }

    public function testAliasIsNotMistakenForAKeywordClause(): void
    {
        // `WHERE` after the table must not be swallowed as its alias.
        [$kind, $cands] = $this->at('SELECT 1 FROM users WHERE e|');
        $this->assertSame(['column', ['email']], [$kind, $cands]);
    }

    public function testInsertColumnListUsesTheIntoTarget(): void
    {
        [$kind, $cands] = $this->at('INSERT INTO users (|');
        $this->assertSame(['column', ['id', 'name', 'email']], [$kind, $cands]);

        [, $cands] = $this->at('INSERT INTO orders (id, us|');
        $this->assertSame(['user_id'], $cands);

        [$kind] = $this->at('INSERT INTO users (id) VALUES (|');
        $this->assertSame('keyword', $kind, 'VALUES operands are literals, not columns');
    }

    public function testQuotedIdentifierContext(): void
    {
        [$kind, $cands, $prefix] = $this->at('SELECT * FROM "ord|');
        $this->assertSame(['table', ['orders', 'order items'], 'ord'], [$kind, $cands, $prefix], 'inside a quote names stay raw');

        $c = Completer::complete('SELECT * FROM "ord', 17, self::TABLES, null);
        $this->assertSame('"', $c->closeQuote, 'an unterminated quote is closed on insertion');

        [$kind, $cands] = $this->at('SELECT "u"."|" FROM users "u"');
        $this->assertSame('qualified', $kind);
        $this->assertSame(['id', 'name', 'email'], $cands);
    }

    public function testNeedsQuotingNamesAreQuotedPerFlavorWhenBare(): void
    {
        [, $cands] = $this->at('SELECT * FROM order|', null, Flavor::MySQL);
        $this->assertSame(['orders', '`order items`'], $cands);

        [, $cands] = $this->at('SELECT * FROM order|', null, Flavor::Postgres);
        $this->assertSame(['orders', '"order items"'], $cands);
    }

    public function testStringsAndCommentsNeverComplete(): void
    {
        foreach (["SELECT 'abc|' FROM users", 'SELECT 1 -- note fr|', 'SELECT /* fr| */ 1'] as $sql) {
            [$kind, $cands] = $this->at($sql);
            $this->assertSame(['none', []], [$kind, $cands], $sql);
        }
    }

    public function testKeywordInsideStringDoesNotChangeContext(): void
    {
        [$kind] = $this->at("SELECT 'FROM' |");
        $this->assertSame('keyword', $kind);
    }

    public function testOnlyTheStatementAtTheCaretCounts(): void
    {
        [$kind, $cands] = $this->at('SELECT 1 FROM orders; SELECT | FROM users');
        $this->assertSame(['column', ['id', 'name', 'email']], [$kind, $cands]);

        [, $cands] = $this->at('SELECT * FROM users; SELECT * FROM |');
        $this->assertContains('orders', $cands);
    }

    public function testNoSchemaFallsBackToKeywords(): void
    {
        [$kind, $cands] = $this->at('SELECT * FROM sel|', []);
        $this->assertSame('keyword', $kind);
        $this->assertContains('SELECT', $cands);

        [$kind] = $this->at('SELECT | FROM users', []);
        $this->assertSame('keyword', $kind);
    }

    public function testStatementStartAndAfterIdentifierAreKeywordContexts(): void
    {
        [$kind, $cands] = $this->at('sel|');
        $this->assertSame(['keyword', ['SELECT']], [$kind, $cands]);

        [$kind] = $this->at('SELECT id | FROM users');
        $this->assertSame('keyword', $kind);

        [$kind] = $this->at('SELECT * |');
        $this->assertSame('keyword', $kind, 'a wildcard is not an operator awaiting an operand');
    }

    public function testColumnsAreOnlyFetchedForTablesTheStatementNames(): void
    {
        $asked = [];
        $c = Completer::complete(
            'SELECT | FROM users',
            7,
            self::TABLES,
            static function (string $t) use (&$asked): array {
                $asked[] = $t;
                return self::columnsOf($t);
            },
        );
        $this->assertSame('column', $c->kind);
        $this->assertSame(['users'], $asked);
    }

    public function testMultibyteCaretOffsetsAreCodepoints(): void
    {
        $c = Completer::complete("SELECT 'é' , na FROM users", 15, self::TABLES, static fn (string $t): array => self::columnsOf($t));
        $this->assertSame(['column', 'na', ['name']], [$c->kind, $c->prefix, $c->candidates]);
    }

    public function testInsertionAdvancesCaseInsensitiveCommonPrefix(): void
    {
        $c = Completer::complete('SELECT * FROM o', 15, ['Orders', 'orders_old'], null);
        $this->assertSame('Orders', Completer::insertion($c), 'prefix advances, spelled as the first candidate writes it');

        $c = Completer::complete('SELECT * FROM ', 14, ['users', 'orders'], null);
        $this->assertSame('', Completer::insertion($c), 'ambiguous with nothing shared -> list');

        $c = Completer::complete('SELECT * FROM us', 16, ['users'], null);
        $this->assertSame('users', Completer::insertion($c));
    }

    public function testTokenizerKeepsQuotesCommentsAndOffsets(): void
    {
        $t = SqlTokenizer::tokenize('a."b c" /* x */ \'it\'\'s\' -- t');
        $this->assertSame(['word', 'punct', 'quoted', 'comment', 'string', 'comment'], array_column($t, 't'));
        $this->assertSame('b c', SqlTokenizer::unquote($t[2]));
        $this->assertSame([0, 1, 2], array_slice(array_column($t, 's'), 0, 3));
        $open = SqlTokenizer::tokenize('"abc');
        $this->assertTrue($open[0]['open']);
        $this->assertSame('abc', SqlTokenizer::unquote($open[0]));
    }
}
