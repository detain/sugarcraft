<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Command\TableCommand;
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

    /**
     * scriptui-28 — gum's spellings must parse: --delimiter is a pure
     * alias of --separator and --has-header of --header. Both must
     * produce byte-identical renders to their canonical counterparts.
     */
    public function testDelimiterAliasRendersIdenticallyToSeparator(): void
    {
        $csv = $this->writeTmp("a;b;c\n1;2;3\n");

        $canon  = $this->runTable(['--file' => $csv, '--separator' => ';']);
        $aliased = $this->runTable(['--file' => $csv, '--delimiter' => ';']);

        $this->assertSame(0, $canon[0]);
        $this->assertSame($canon[1], $aliased[1], '--delimiter must be the same option');
        $this->assertStringContainsString('1', $aliased[1]);
    }

    public function testHasHeaderAliasRendersIdenticallyToHeader(): void
    {
        $csv = $this->writeTmp("name;age\namy;30\nbob;41\n");

        $canon  = $this->runTable(['--file' => $csv, '--separator' => ';', '--header' => true]);
        $aliased = $this->runTable(['--file' => $csv, '--separator' => ';', '--has-header' => true]);

        $this->assertSame(0, $canon[0]);
        $this->assertSame($canon[1], $aliased[1], '--has-header must be the same flag');
        // Header row is hoisted out of the body: name is the column title.
        $this->assertStringContainsString('name', $aliased[1]);
    }

    public function testConflictingSeparatorAndDelimiterIsRefused(): void
    {
        $csv = $this->writeTmp("a;b;c\n1;2;3\n");

        [$status] = $this->runTable(['--file' => $csv, '--separator' => ';', '--delimiter' => ',']);

        $this->assertSame(1, $status, 'different values for both spellings must fail, not last-wins');
    }

    public function testAgreeingSeparatorAndDelimiterIsAccepted(): void
    {
        $csv = $this->writeTmp("a;b;c\n1;2;3\n");

        [$status, $display] = $this->runTable(['--file' => $csv, '--separator' => ';', '--delimiter' => ';']);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('2', $display);
    }

    public function testHelpListsCanonicalAndAliasOptions(): void
    {
        $definition = (new TableCommand())->getDefinition();

        $this->assertTrue($definition->hasOption('delimiter'));
        $this->assertTrue($definition->hasOption('has-header'));
        $this->assertStringContainsString('--delimiter', $definition->getOption('separator')->getDescription());
        $this->assertStringContainsString('--has-header', $definition->getOption('header')->getDescription());
    }

    /** @param array<string,mixed> $input @return array{0:int,1:string} */
    private function runTable(array $input): array
    {
        $tester = new \Symfony\Component\Console\Tester\CommandTester(new TableCommand());
        $status = $tester->execute($input);
        return [$status, $tester->getDisplay()];
    }

    private function writeTmp(string $contents): string
    {
        $path = sys_get_temp_dir() . '/scriptui_table_' . getmypid() . '.csv';
        file_put_contents($path, $contents);
        $this->filesToClean[] = $path;
        return $path;
    }

    /** @var list<string> */
    private array $filesToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->filesToClean as $f) {
            @unlink($f);
        }
        $this->filesToClean = [];
    }
}
