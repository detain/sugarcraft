<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\ParsedArgs;

/**
 * Tests for {@see ArgvParser} — argv parsing for sugarcrush CLI.
 *
 * Covered cases:
 *   - help flag (-h, --help)
 *   - prompt flag (-p, --prompt, --prompt=<value>)
 *   - root path extraction (positional path arguments)
 *   - unknown flags are silently ignored
 */
final class ArgvParserTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Help flag
    // -------------------------------------------------------------------------

    public function testHelpDefaultsToFalse(): void
    {
        $result = ArgvParser::parse(['sugarcrush']);

        $this->assertFalse($result->help);
    }

    public function testDashHEnablesHelp(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '-h']);

        $this->assertTrue($result->help);
    }

    public function testDashHelpEnablesHelp(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--help']);

        $this->assertTrue($result->help);
    }

    // -------------------------------------------------------------------------
    // Prompt flag
    // -------------------------------------------------------------------------

    public function testPromptDefaultsToNull(): void
    {
        $result = ArgvParser::parse(['sugarcrush']);

        $this->assertNull($result->prompt);
    }

    public function testDashPSetsPrompt(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '-p', 'write a test']);

        $this->assertSame('write a test', $result->prompt);
    }

    public function testLongPromptWithSpaceSetsPrompt(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--prompt', 'explain this code']);

        $this->assertSame('explain this code', $result->prompt);
    }

    public function testLongPromptWithEqualsSetsPrompt(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--prompt=hello world']);

        $this->assertSame('hello world', $result->prompt);
    }

    // -------------------------------------------------------------------------
    // --output-format
    // -------------------------------------------------------------------------

    public function testOutputFormatDefaultsToText(): void
    {
        $result = ArgvParser::parse(['sugarcrush']);

        $this->assertSame('text', $result->outputFormat);
        $this->assertSame(ParsedArgs::DEFAULT_OUTPUT_FORMAT, $result->outputFormat);
    }

    public function testOutputFormatWithSpaceSetsOutputFormat(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '-p', 'x', '--output-format', 'json']);

        $this->assertSame('json', $result->outputFormat);
    }

    public function testOutputFormatWithEqualsSetsOutputFormat(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '-p', 'x', '--output-format=json']);

        $this->assertSame('json', $result->outputFormat);
    }

    // -------------------------------------------------------------------------
    // Root path extraction
    // -------------------------------------------------------------------------

    public function testRootDefaultsToNull(): void
    {
        $result = ArgvParser::parse(['sugarcrush']);

        $this->assertNull($result->root);
    }

    public function testAbsolutePathPositionalBecomesRoot(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '/home/user/myproject']);

        $this->assertSame('/home/user/myproject', $result->root);
    }

    public function testRelativePathPositionalBecomesRoot(): void
    {
        $result = ArgvParser::parse(['sugarcrush', './myproject']);

        $this->assertSame('./myproject', $result->root);
    }

    public function testPathWithSeparatorBecomesRoot(): void
    {
        $result = ArgvParser::parse(['sugarcrush', 'src/commands']);

        $this->assertSame('src/commands', $result->root);
    }

    public function testNonPathPositionalDoesNotBecomeRoot(): void
    {
        $result = ArgvParser::parse(['sugarcrush', 'hello']);

        $this->assertNull($result->root);
    }

    public function testPromptBeforeRootDoesNotConsumeRoot(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '-p', 'fix the bug', '/code/project']);

        $this->assertSame('fix the bug', $result->prompt);
        $this->assertSame('/code/project', $result->root);
    }

    public function testRootFlagOverridesPositionalPath(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '/wrong/path', '--root', '/correct/path']);

        $this->assertSame('/correct/path', $result->root);
    }

    public function testRootEqualsFlagOverridesPositionalPath(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '/wrong/path', '--root=/correct/path']);

        $this->assertSame('/correct/path', $result->root);
    }

    // -------------------------------------------------------------------------
    // Unknown flags
    // -------------------------------------------------------------------------

    public function testUnknownFlagIsIgnored(): void
    {
        // Should not throw, should not set any field
        $result = ArgvParser::parse(['sugarcrush', '--unknown-flag', 'value']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertNull($result->root);
    }

    public function testUnknownShortFlagIsIgnored(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '-z']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertNull($result->root);
    }

    public function testUnknownFlagWithValueIsIgnored(): void
    {
        // -z without a following argument advances the index, so we just
        // verify no exception is thrown and defaults remain.
        $result = ArgvParser::parse(['sugarcrush', '-z', 'value']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
    }

    public function testUnknownFlagDoesNotAffectKnownFlags(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--unknown', '-p', 'hello', '--also-unknown']);

        $this->assertSame('hello', $result->prompt);
        $this->assertFalse($result->help);
    }

    // -------------------------------------------------------------------------
    // Combined / edge cases
    // -------------------------------------------------------------------------

    public function testHelpWithPromptAndRoot(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--help', '-p', 'hello', '/path']);

        $this->assertTrue($result->help);
        $this->assertSame('hello', $result->prompt);
        $this->assertSame('/path', $result->root);
    }

    public function testEmptyArgvReturnsDefaults(): void
    {
        // $argv with only script name
        $result = ArgvParser::parse(['sugarcrush']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertNull($result->root);
    }

    public function testPromptValueCanBeNextFlag(): void
    {
        // When -p is followed by another flag (not a value), the next flag
        // is consumed as the value. This mirrors getopt behaviour in pop.
        $result = ArgvParser::parse(['sugarcrush', '-p', '--help']);

        $this->assertSame('--help', $result->prompt);
    }
}
