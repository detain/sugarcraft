<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\QueryParams;

/**
 * SQL-O23 (once-030): placeholder detection is the gate into the bind
 * prompt, so its syntax scan is load-bearing security surface — anything it
 * misreads either ships a hand-interpolated literal or refuses a runnable
 * statement. The legs cover every region a `?` or `:name` may legitimately
 * NOT appear in: quoted strings (doubling and backslash escapes), quoted
 * identifiers, line and block comments, the PostgreSQL ::cast, and time
 * literals like ':30'.
 */
final class QueryParamsTest extends TestCase
{
    public function testPositionalPlaceholdersAreDetectedInOrder(): void
    {
        $this->assertSame([null, null], QueryParams::detect('SELECT * FROM t WHERE a = ? AND b = ?'));
    }

    public function testNamedPlaceholdersAreDetectedByName(): void
    {
        $this->assertSame(['alpha', 'b2'], QueryParams::detect('SELECT * FROM t WHERE a = :alpha AND b = :b2'));
    }

    public function testRepeatedNamedPlaceholderYieldsBothOccurrences(): void
    {
        $this->assertSame(['who', 'who'], QueryParams::detect('WHERE name = :who OR alt = :who'));
    }

    public function testQuotedStringsHideTheirQuestionMarks(): void
    {
        $this->assertSame([null], QueryParams::detect("SELECT * FROM t WHERE x = '?' AND y = ?"));
        $this->assertSame([null], QueryParams::detect('SELECT * FROM t WHERE x = "?" AND y = ?'));
    }

    public function testDoubledQuoteEscapeKeepsTheStringOpen(): void
    {
        // The '?' lives INSIDE 'it''s ? still here' — the doubled quote is
        // data, not a close.
        $this->assertSame([null], QueryParams::detect("WHERE x = 'it''s ?' AND y = ?"));
    }

    public function testBackslashEscapeKeepsTheStringOpen(): void
    {
        $this->assertSame([null], QueryParams::detect("WHERE x = 'a\\?b' AND y = ?"));
    }

    public function testQuotedIdentifiersAreOpaque(): void
    {
        $this->assertSame([null], QueryParams::detect('SELECT `a?b`, ? FROM t'));
    }

    public function testCommentsHidePlaceholders(): void
    {
        $this->assertSame([null, null], QueryParams::detect("WHERE a = ? -- ?\nAND b = ?"));
        $this->assertSame([null], QueryParams::detect('SELECT ? # ? trailing'));
        $this->assertSame([null], QueryParams::detect('/* ? */ SELECT ?'));
    }

    public function testPostgresCastIsNotAPlaceholder(): void
    {
        $this->assertSame([], QueryParams::detect('SELECT a::text FROM t WHERE b = NULL'));
    }

    public function testTimeLiteralsAreNotNamedPlaceholders(): void
    {
        $this->assertSame([], QueryParams::detect("SELECT CAST('x' AS TIME) = '12:30'"));
        // A digit-led colon run is a time-ish literal, not a name.
        $this->assertSame([], QueryParams::detect('WHERE t > :30'));
    }

    public function testMixingFormsIsFlagged(): void
    {
        $detected = QueryParams::detect('WHERE a = ? AND b = :x');
        $this->assertSame([null, 'x'], $detected);
        $this->assertTrue(QueryParams::isMixed($detected));
        $this->assertFalse(QueryParams::isMixed(QueryParams::detect('WHERE a = ? AND b = ?')));
        $this->assertFalse(QueryParams::isMixed(QueryParams::detect('WHERE a = :x AND b = :y')));
    }

    public function testPlainStatementHasNoPlaceholders(): void
    {
        $this->assertSame([], QueryParams::detect('SELECT 1'));
    }
}
