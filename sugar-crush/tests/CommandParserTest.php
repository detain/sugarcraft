<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests;

use SugarCraft\Crush\CommandParser;
use SugarCraft\Crush\ParsedCommand;
use PHPUnit\Framework\TestCase;

final class CommandParserTest extends TestCase
{
    private CommandParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CommandParser();
    }

    public function testPlainTextReturnsNull(): void
    {
        $this->assertNull($this->parser->parse('hello world'));
        $this->assertNull($this->parser->parse('  hello world'));
        $this->assertNull($this->parser->parse(''));
    }

    public function testSlashCommandIsDetected(): void
    {
        $result = $this->parser->parse('/filter foo');
        $this->assertNotNull($result);
        $this->assertSame('filter', $result->name);
    }

    public function testCommandNameIsLowercased(): void
    {
        $result = $this->parser->parse('/FILTER foo');
        $this->assertNotNull($result);
        $this->assertSame('filter', $result->name);
    }

    public function testSinglePositionalArgument(): void
    {
        $result = $this->parser->parse('/filter error');
        $this->assertNotNull($result);
        $this->assertSame('filter', $result->name);
        $this->assertSame(['error'], $result->args);
    }

    public function testMultiplePositionalArguments(): void
    {
        $result = $this->parser->parse('/select 10 20');
        $this->assertNotNull($result);
        $this->assertSame('select', $result->name);
        $this->assertSame(['10', '20'], $result->args);
    }

    public function testColonSeparatesCommandFromArgs(): void
    {
        $result = $this->parser->parse('/goto:50');
        $this->assertNotNull($result);
        $this->assertSame('goto', $result->name);
        $this->assertSame(['50'], $result->args);
    }

    public function testColonWithSpaceSeparatesCommandFromArgs(): void
    {
        $result = $this->parser->parse('/goto: 50');
        $this->assertNotNull($result);
        $this->assertSame('goto', $result->name);
        $this->assertSame(['50'], $result->args);
    }

    public function testHyphenInCommandName(): void
    {
        $result = $this->parser->parse('/my-command arg');
        $this->assertNotNull($result);
        $this->assertSame('my-command', $result->name);
    }

    public function testUnderscoreInCommandName(): void
    {
        $result = $this->parser->parse('/my_command arg');
        $this->assertNotNull($result);
        $this->assertSame('my_command', $result->name);
    }

    public function testCommandAloneNoArgs(): void
    {
        $result = $this->parser->parse('/quit');
        $this->assertNotNull($result);
        $this->assertSame('quit', $result->name);
        $this->assertSame([], $result->args);
    }

    public function testOnlySlashReturnsNull(): void
    {
        $this->assertNull($this->parser->parse('/'));
        $this->assertNull($this->parser->parse('/ '));
    }

    public function testArgsWithQuotedStrings(): void
    {
        $result = $this->parser->parse('/filter "hello world"');
        $this->assertNotNull($result);
        $this->assertSame(['hello world'], $result->args);
    }

    public function testArgsWithSingleQuotedStrings(): void
    {
        $result = $this->parser->parse("/filter 'hello world'");
        $this->assertNotNull($result);
        $this->assertSame(['hello world'], $result->args);
    }

    public function testArgsWithMultipleQuotedStrings(): void
    {
        $result = $this->parser->parse('/filter "foo bar" baz');
        $this->assertNotNull($result);
        $this->assertSame(['foo bar', 'baz'], $result->args);
    }

    /**
     * Audit 15b-23: an apostrophe inside a word is prose, not a quote. It used
     * to open a single-quoted span that ran to the end of the line, so `$1` got
     * the whole sentence with the apostrophe deleted.
     */
    public function testApostropheInsideAWordIsLiteral(): void
    {
        $this->assertSame(
            ['fix', "don't", 'touch', 'main.php'],
            $this->parser->parse("/c fix don't touch main.php")?->args,
        );
        $this->assertSame(
            ["it's", 'src/a.php', 'src/b.php'],
            $this->parser->parse("/c it's src/a.php src/b.php")?->args,
        );
    }

    /** Audit 15b-23: `""` is an argument the user typed; dropping it shifts `$2` into `$1`. */
    public function testEmptyQuotedArgumentIsKept(): void
    {
        $this->assertSame(['', 'second'], $this->parser->parse('/c "" second')?->args);
        $this->assertSame(['first', '', 'third'], $this->parser->parse("/c first '' third")?->args);
        $this->assertSame([''], $this->parser->parse('/c ""')?->args);
    }

    /** Audit 15b-23: an unterminated quote is kept literally and the rest still splits. */
    public function testUnterminatedQuoteIsKeptLiterally(): void
    {
        $this->assertSame(["'abc", 'def'], $this->parser->parse("/c 'abc def")?->args);
        $this->assertSame(['one', '"two', 'three'], $this->parser->parse('/c one "two three')?->args);
        $this->assertSame(['"'], $this->parser->parse('/c "')?->args);
    }

    public function testQuoteOfTheOtherKindIsLiteralInsideAQuotedSpan(): void
    {
        $this->assertSame(
            ["it's here", 'say "hi"'],
            $this->parser->parse("/c \"it's here\" 'say \"hi\"'")?->args,
        );
    }

    public function testQuoteInsideAWordStaysLiteralAndClosedQuoteJoinsTrailingText(): void
    {
        $this->assertSame(['a"b', 'c'], $this->parser->parse('/c a"b c')?->args);
        $this->assertSame(['foo barbaz', 'x'], $this->parser->parse('/c "foo bar"baz x')?->args);
    }

    public function testQuotedArgumentsMixWithPlainOnesAndTabs(): void
    {
        $this->assertSame(
            ['us east', 'prod', 'x y'],
            $this->parser->parse("/deploy \"us east\"\tprod   'x y'")?->args,
        );
    }

    public function testLeadingWhitespaceIsSkipped(): void
    {
        $result = $this->parser->parse('  /filter foo');
        $this->assertNotNull($result);
        $this->assertSame('filter', $result->name);
        $this->assertSame(['foo'], $result->args);
    }

    public function testCommandNameNormalizationStripsSpecialChars(): void
    {
        $result = $this->parser->parse('/filter!@#$ foo');
        $this->assertNotNull($result);
        $this->assertSame('filter', $result->name);
    }

    public function testParsedCommandIsImmutable(): void
    {
        $result = $this->parser->parse('/filter foo');
        $this->assertNotNull($result);
        $withMore = $result->withArgs(['foo', 'bar']);
        $this->assertSame(['foo'], $result->args);
        $this->assertSame(['foo', 'bar'], $withMore->args);
    }
}
