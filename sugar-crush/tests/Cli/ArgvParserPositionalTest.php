<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\ParsedArgs;

/**
 * Audit CLI-2: bare operands the parser does not claim.
 *
 * `sugarcrush src` used to ignore `src` (only a path-SHAPED operand became the
 * root), `sugarcrush fix the login bug` dropped every word and opened the TUI
 * in the cwd, `-p hi -- extra` lost `extra`, and `--root --model x` made the
 * root the literal "--model". {@see ArgvParser::parse()} now carries what it
 * did not claim in {@see ParsedArgs::$positionals}, and
 * {@see ArgvParser::resolveOperands()} -- the filesystem half, which
 * bin/sugarcrush calls after the unknown-flag check -- makes an existing
 * directory the root and refuses the rest.
 *
 * Each case runs in a scratch cwd holding `src/` and `lib/`, so a bare `src`
 * is an existing directory and `fix` is not.
 */
final class ArgvParserPositionalTest extends TestCase
{
    private string $previousCwd = '';

    private string $scratch = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousCwd = (string) \getcwd();
        $this->scratch = \sys_get_temp_dir() . '/sugarcrush_positional_' . \uniqid('', true);
        \mkdir($this->scratch . '/src', 0700, true);
        \mkdir($this->scratch . '/lib', 0700, true);
        \chdir($this->scratch);
    }

    protected function tearDown(): void
    {
        \chdir($this->previousCwd);
        @\rmdir($this->scratch . '/src');
        @\rmdir($this->scratch . '/lib');
        @\rmdir($this->scratch);

        parent::tearDown();
    }

    /**
     * @param list<string> $tokens
     */
    private static function resolve(array $tokens): ParsedArgs
    {
        return ArgvParser::resolveOperands(ArgvParser::parse(\array_merge(['sugarcrush'], $tokens)));
    }

    // -------------------------------------------------------------------------
    // An existing directory becomes the root
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function directoryOperands(): array
    {
        return [
            'bare directory'                 => [['src'], 'src'],
            'bare directory after --'        => [['--', 'src'], 'src'],
            'bare directory beside -p'       => [['-p', 'hi', 'src'], 'src'],
            'bare directory before a subcommand' => [['src', 'mcp', 'list'], 'src'],
            'bare directory after flags'     => [['--output-format', 'json', 'src'], 'src'],
        ];
    }

    /**
     * @param list<string> $tokens
     *
     * @dataProvider directoryOperands
     */
    public function testAnExistingDirectoryOperandBecomesTheRoot(array $tokens, string $root): void
    {
        $args = self::resolve($tokens);

        $this->assertNull($args->usageError, (string) $args->usageError);
        $this->assertSame($root, $args->root);
        $this->assertSame([], $args->positionals);
        $this->assertNull(ArgvParser::rootError($args));
    }

    /**
     * parse() stays a pure argv transform: it never asks the filesystem, so
     * the bare word is still only a carried operand at that stage.
     */
    public function testParseItselfLeavesABareDirectoryAsAnOperand(): void
    {
        $args = ArgvParser::parse(['sugarcrush', 'src']);

        $this->assertNull($args->root);
        $this->assertSame(['src'], $args->positionals);
        $this->assertNull($args->usageError);
    }

    /**
     * A path-shaped operand keeps its old meaning: parse() makes it the root
     * without looking, and rootError() is still what reports that it is
     * missing.
     */
    public function testAPathShapedOperandIsStillRoutedToRootError(): void
    {
        $args = self::resolve(['./no-such-dir']);

        $this->assertNull($args->usageError);
        $this->assertSame('./no-such-dir', $args->root);
        $this->assertSame([], $args->positionals);
        $this->assertStringContainsString('no such directory', (string) ArgvParser::rootError($args));
    }

    // -------------------------------------------------------------------------
    // Every other operand is a usage error
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: string}>
     */
    public static function refusedOperands(): array
    {
        return [
            'a prompt typed without -p' => [
                ['fix', 'the', 'bug'],
                'sugarcrush: unexpected arguments: fix, the, bug',
                'use -p "<prompt>"',
            ],
            'one stray word' => [
                ['fix'],
                'sugarcrush: unexpected argument: fix',
                'use -p "<prompt>"',
            ],
            'a word after the prompt' => [
                ['-p', 'hi', 'extra'],
                'sugarcrush: unexpected argument after the prompt: extra',
                'Quote the whole prompt as one argument',
            ],
            'a word after -- after the prompt' => [
                ['-p', 'hi', '--', 'extra'],
                'sugarcrush: unexpected argument after the prompt: extra',
                'Quote the whole prompt as one argument',
            ],
            'a word after run' => [
                ['run', 'hi', 'extra'],
                'sugarcrush: unexpected argument after the prompt: extra',
                'Quote the whole prompt as one argument',
            ],
            'an empty operand' => [
                [''],
                "sugarcrush: unexpected argument: ''",
                'use -p "<prompt>"',
            ],
            // Two directories cannot both be the root; the first one is, and
            // the second is refused rather than ignored.
            'two bare directories' => [
                ['src', 'lib'],
                'sugarcrush: the project root is already src, but the argument lib also names one',
                'Name the project directory once',
            ],
            'a path-shaped root and a bare directory' => [
                ['./src', 'lib'],
                'sugarcrush: the project root is already ./src, but the argument lib also names one',
                'Name the project directory once',
            ],
            'two path-shaped operands' => [
                ['./src', './lib'],
                'sugarcrush: the project root is already ./src, but the argument ./lib also names one',
                'Name the project directory once',
            ],
            // --root USED to win over the operand silently; it is now an error.
            '--root beside a bare directory' => [
                ['--root', '/tmp', 'src'],
                'sugarcrush: the project root is already /tmp, but the argument src also names one',
                'Name the project directory once',
            ],
            '--root beside a path-shaped operand' => [
                ['./src', '--root=/tmp'],
                'sugarcrush: the project root is already /tmp, but the argument ./src also names one',
                'Name the project directory once',
            ],
            // A word beside a root is the ordinary refusal, not the "two
            // roots" one -- the word names no directory.
            'a word beside --root' => [
                ['--root', '/tmp', 'fix'],
                'sugarcrush: unexpected argument: fix',
                'use -p "<prompt>"',
            ],
            'a stray word before a subcommand' => [
                ['fix', 'session', 'list'],
                'sugarcrush: unexpected argument: fix',
                'use -p "<prompt>"',
            ],
        ];
    }

    /**
     * @param list<string> $tokens
     *
     * @dataProvider refusedOperands
     */
    public function testALeftoverOperandIsAUsageError(array $tokens, string $error, string $hintFragment): void
    {
        $args = self::resolve($tokens);

        $this->assertSame($error, $args->usageError);
        $this->assertStringContainsString($hintFragment, (string) $args->usageHint);
    }

    /**
     * A subcommand's operands are collected separately and are never offered
     * to the root or the refusal, even when one of them names a directory.
     */
    public function testSubcommandOperandsAreUnaffected(): void
    {
        $args = self::resolve(['session', 'delete', 'src']);

        $this->assertNull($args->usageError);
        $this->assertNull($args->root);
        $this->assertSame(['delete', 'src'], $args->subcommandArgs);
        $this->assertSame([], $args->positionals);
    }

    /**
     * The parser's own usage error is the earlier and sharper message, so a
     * leftover operand beside it must not replace it.
     */
    public function testAParserUsageErrorIsNotOverwritten(): void
    {
        $parsed = ArgvParser::parse(['sugarcrush', '-p', '--verbose', 'fix']);
        $args = ArgvParser::resolveOperands($parsed);

        $this->assertNotNull($parsed->usageError);
        $this->assertSame($parsed->usageError, $args->usageError);
        $this->assertSame($parsed->usageHint, $args->usageHint);
    }

    // -------------------------------------------------------------------------
    // --root refuses a flag-shaped or missing value, like its siblings
    // -------------------------------------------------------------------------

    public function testRootHandedAnOptionIsAUsageErrorAndTheOptionStillApplies(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '--root', '--model', 'x']);

        $this->assertSame(
            'sugarcrush: --root expects a directory, but the next argument is the option --model',
            $args->usageError,
        );
        $this->assertStringContainsString('--root=<dir>', (string) $args->usageHint);
        $this->assertNull($args->root);
        // The flag was left for the loop, so it was parsed as itself and its
        // value is not a stray operand.
        $this->assertSame('x', $args->model);
        $this->assertSame([], $args->positionals);
    }

    public function testRootAtTheEndOfTheArgumentListIsAUsageError(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '--root']);

        $this->assertSame('sugarcrush: --root expects a directory, but the argument list ended', $args->usageError);
        $this->assertNull($args->root);
    }

    public function testRootHandedTheSeparatorIsAUsageError(): void
    {
        $args = ArgvParser::parse(['sugarcrush', '--root', '--', 'src']);

        $this->assertSame(
            'sugarcrush: --root expects a directory, but the next argument is the option --',
            $args->usageError,
        );
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function rootValuesThatStillWork(): array
    {
        return [
            'the equals form takes a dash-led value' => [['--root=-dir'], '-dir'],
            'a lone dash is not flag-shaped'         => [['--root', '-'], '-'],
            'an ordinary directory'                  => [['--root', '/tmp'], '/tmp'],
        ];
    }

    /**
     * @param list<string> $tokens
     *
     * @dataProvider rootValuesThatStillWork
     */
    public function testRootValuesThatAreNotOptionsAreStillTaken(array $tokens, string $root): void
    {
        $args = ArgvParser::parse(\array_merge(['sugarcrush'], $tokens));

        $this->assertNull($args->usageError);
        $this->assertSame($root, $args->root);
    }
}
