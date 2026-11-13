<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Command\TableCommand;

/**
 * scriptui-30 (finding 040): the CSV lane reads like Go's encoding/csv —
 * a backslash is ordinary DATA, only a DOUBLED quote escapes a quote, and
 * the reading is byte-constant on every PHP the manifest admits. The old
 * fgetcsv(escape:'\\') folded `\"` inside quotes (a non-RFC behaviour PHP
 * 8.4 deprecates and 9.0 removes); those goldens are superseded here.
 */
final class TableCsvEscapeTest extends TestCase
{
    public function testBackslashInsideQuotesIsLiteralData(): void
    {
        // Chosen arm (documented in parseCsv): after the closing quote the
        // rest of the segment rides verbatim. fgetcsv's old fold produced
        // 'b"x' by treating \" as an escaped quote — Go never does.
        self::assertSame(
            [['a', 'b\x"', 'c']],
            TableCommand::parseRows('a,"b\"x",c', ','),
        );
    }

    public function testBackslashOutsideQuotesNeedsNoEscape(): void
    {
        self::assertSame(
            [['a', 'b\\c', 'd']],
            TableCommand::parseRows('a,b\\c,d', ','),
        );
    }

    public function testDoubledQuoteStillEscapesTheQuote(): void
    {
        self::assertSame(
            [['a', 'b"x', 'c']],
            TableCommand::parseRows('a,"b""x",c', ','),
        );
    }

    public function testEmptyQuotedFieldStaysEmpty(): void
    {
        self::assertSame(
            [['a', '', 'c']],
            TableCommand::parseRows('a,"",c', ','),
        );
    }

    public function testWindowsPathStyleDataIsUntouched(): void
    {
        // The shape that motivated the finding: literal paths with
        // backslashes must round-trip, not fold.
        self::assertSame(
            [['C:\\Users\\me', 'docs']],
            TableCommand::parseRows('"C:\\Users\\me",docs', ','),
        );
    }

    public function testNoFgetcsvEscapeArgleavesTheSource(): void
    {
        // Gate-proof: the deprecated (8.4) escape argument must be gone —
        // failOnWarning="true" would turn a stray deprecation into red CI.
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Command/TableCommand.php',
        );
        $this->assertStringNotContainsString('fgetcsv', $src);
    }
}
