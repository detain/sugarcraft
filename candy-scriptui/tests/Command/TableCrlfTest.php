<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use PHPUnit\Framework\TestCase;
use SugarCraft\ScriptUi\Command\TableCommand;

/**
 * scriptui-38 (finding 056): CRLF input must yield the same rows on BOTH
 * separator paths. The multi-char fallback used to split on bare LF and
 * keep a literal CR inside the last cell of every row (od-proven
 * `b \r │`), while the single-char fgetcsv path handled CRLF cleanly.
 */
final class TableCrlfTest extends TestCase
{
    public function testMultiCharSeparatorDropsCarriageReturns(): void
    {
        $rows = TableCommand::parseRows("a::b\r\nc::d\r\n", '::');
        self::assertSame(
            [['a', 'b'], ['c', 'd']],
            $rows,
            'no stray CR may survive into any cell',
        );
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                self::assertStringNotContainsString("\r", $cell);
            }
        }
    }

    public function testBothSeparatorPathsAgreeOnCrlf(): void
    {
        $single = TableCommand::parseRows("a,b\r\nc,d\r\n", ',');
        $multi  = TableCommand::parseRows("a%%b\r\nc%%d\r\n", '%%');
        self::assertSame($single, $multi, 'the two parse lanes must not disagree byte-wise');
        self::assertSame([['a', 'b'], ['c', 'd']], $single);
    }

    public function testBareCrLineEndingsAlsoSplit(): void
    {
        $rows = TableCommand::parseRows("a::b\rc::d", '::');
        self::assertSame([['a', 'b'], ['c', 'd']], $rows);
    }

    public function testQuotedMultilineFieldSurvivesTheSplit(): void
    {
        // The RFC lane must keep embedded newlines inside quotes together.
        $rows = TableCommand::parseRows("a,\"line1\nline2\",c\n", ',');
        self::assertSame([['a', "line1\nline2", 'c']], $rows);
    }
}
