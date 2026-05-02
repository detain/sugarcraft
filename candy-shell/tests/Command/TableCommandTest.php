<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Command;

use CandyCore\Shell\Command\TableCommand;
use PHPUnit\Framework\TestCase;

final class TableCommandTest extends TestCase
{
    public function testParseCsvRows(): void
    {
        $rows = TableCommand::parseRows("a,b,c\n1,2,3\n", ',');
        $this->assertSame([['a','b','c'],['1','2','3']], $rows);
    }

    public function testParseTsvRows(): void
    {
        $rows = TableCommand::parseRows("a\tb\n1\t2", "\t");
        $this->assertSame([['a','b'],['1','2']], $rows);
    }

    public function testParseRowsHandlesQuotedFields(): void
    {
        $rows = TableCommand::parseRows('"hello, world",ok', ',');
        $this->assertSame([['hello, world', 'ok']], $rows);
    }

    public function testParseRowsHandlesQuotedNewlines(): void
    {
        // A quoted field with an embedded newline must stay in one row.
        $csv = "name,note\n\"Ada\",\"line one\nline two\"\n\"Bob\",\"hi\"";
        $rows = TableCommand::parseRows($csv, ',');
        $this->assertSame([
            ['name', 'note'],
            ['Ada',  "line one\nline two"],
            ['Bob',  'hi'],
        ], $rows);
    }

    public function testParseRowsHandlesEscapedQuotes(): void
    {
        $rows = TableCommand::parseRows('"He said ""hi""",2', ',');
        $this->assertSame([['He said "hi"', '2']], $rows);
    }

    public function testMultiCharSeparatorStillSplitsByLine(): void
    {
        // Multi-char separators have no quoting convention; each line
        // is one row, fields split literally.
        $rows = TableCommand::parseRows("a||b\nc||d", '||');
        $this->assertSame([['a', 'b'], ['c', 'd']], $rows);
    }

    public function testParseBorderNames(): void
    {
        $this->assertNull(TableCommand::parseBorder('none'));
        $this->assertNotNull(TableCommand::parseBorder('rounded'));
        $this->assertNotNull(TableCommand::parseBorder('thick'));
    }

    public function testParseBorderRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TableCommand::parseBorder('zigzag');
    }
}
