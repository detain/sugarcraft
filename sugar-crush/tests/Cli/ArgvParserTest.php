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
 *   - unknown flags are recorded in $unknownFlags, never applied
 *   - $promptRequested tracks "-p/--prompt/run appeared", value or not
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
    // Unknown flags — recorded, never applied (crush_code.md Phase 0 item 3;
    // bin/sugarcrush turns a non-empty $unknownFlags into exit 2)
    // -------------------------------------------------------------------------

    public function testUnknownFlagIsRecordedAndNotApplied(): void
    {
        // Should not throw, should not set any field
        $result = ArgvParser::parse(['sugarcrush', '--unknown-flag', 'value']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertNull($result->root);
        $this->assertSame(['--unknown-flag'], $result->unknownFlags);
    }

    public function testUnknownShortFlagIsRecordedAndNotApplied(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '-z']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertNull($result->root);
        $this->assertSame(['-z'], $result->unknownFlags);
    }

    public function testUnknownFlagWithValueIsRecordedAndNotApplied(): void
    {
        // -z without a following argument advances the index, so we just
        // verify no exception is thrown and defaults remain.
        $result = ArgvParser::parse(['sugarcrush', '-z', 'value']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertSame(['-z'], $result->unknownFlags);
    }

    public function testUnknownFlagDoesNotAffectKnownFlags(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--unknown', '-p', 'hello', '--also-unknown']);

        $this->assertSame('hello', $result->prompt);
        $this->assertFalse($result->help);
        $this->assertSame(['--unknown', '--also-unknown'], $result->unknownFlags);
    }

    public function testEveryUnknownFlagIsRecordedInOrder(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--version', '-z', '--nope']);

        $this->assertSame(['--version', '-z', '--nope'], $result->unknownFlags);
    }

    public function testRecognisedInvocationsRecordNoUnknownFlags(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '-p', 'hello', '--output-format=json', '--root=/tmp/x', '-h']);

        $this->assertSame([], $result->unknownFlags);
    }

    /**
     * `-px` is one unknown token, NOT "-p" plus a clustered "x" — the parser
     * does no short-flag clustering. "hello" then survives as a positional
     * that is not path-shaped, so it is discarded; before Phase 0 item 3 that
     * left an empty ParsedArgs and `sugarcrush -px "hello"` opened the TUI.
     */
    public function testClusteredShortFlagIsTreatedAsOneUnknownFlag(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '-px', 'hello']);

        $this->assertSame(['-px'], $result->unknownFlags);
        $this->assertNull($result->prompt);
        $this->assertFalse($result->promptRequested);
        $this->assertNull($result->root);
    }

    // -------------------------------------------------------------------------
    // promptRequested — "one-shot mode was asked for", value or not. This is
    // what bin/sugarcrush dispatches on, so a bare `-p` reaches
    // NonInteractive::run()'s "no prompt given" error instead of the TUI.
    // -------------------------------------------------------------------------

    public function testPromptRequestedDefaultsToFalse(): void
    {
        $result = ArgvParser::parse(['sugarcrush']);

        $this->assertFalse($result->promptRequested);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string|null}>
     */
    public static function promptRequestingForms(): array
    {
        return [
            'bare -p'            => [['sugarcrush', '-p'], null],
            'bare --prompt'      => [['sugarcrush', '--prompt'], null],
            'bare run'           => [['sugarcrush', 'run'], null],
            'empty --prompt='    => [['sugarcrush', '--prompt='], ''],
            '-p with value'      => [['sugarcrush', '-p', 'hi'], 'hi'],
            '--prompt with value' => [['sugarcrush', '--prompt', 'hi'], 'hi'],
            '--prompt= value'    => [['sugarcrush', '--prompt=hi'], 'hi'],
            'run with value'     => [['sugarcrush', 'run', 'hi'], 'hi'],
        ];
    }

    /**
     * @param list<string> $argv
     *
     * @dataProvider promptRequestingForms
     */
    public function testPromptRequestedIsSetWheneverOneShotModeIsAskedFor(array $argv, ?string $expectedPrompt): void
    {
        $result = ArgvParser::parse($argv);

        $this->assertTrue($result->promptRequested);
        $this->assertSame($expectedPrompt, $result->prompt);
    }

    public function testPromptRequestedIsNotSetByAnUnrelatedFlag(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--output-format=json', '--root=/tmp/x']);

        $this->assertFalse($result->promptRequested);
    }

    /**
     * `run` is only the subcommand alias as the very first argument — the
     * existing "leave a later `run` untouched" contract must not start
     * flipping promptRequested either.
     */
    public function testRunAsANonFirstArgumentDoesNotRequestAPrompt(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--root=/tmp/x', 'run']);

        $this->assertFalse($result->promptRequested);
        $this->assertNull($result->prompt);
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

    // -------------------------------------------------------------------------
    // `--` end-of-options separator
    // -------------------------------------------------------------------------

    /**
     * `sugarcrush -- /tmp/repo` is the one invocation shape that used to do
     * something (set root, open the TUI) and then started exiting 2, because
     * `--` had no handling at all and fell into the unknown-flag recorder.
     */
    public function testDoubleDashIsConsumedAndTheOperandAfterItBecomesRoot(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--', '/tmp/repo']);

        $this->assertSame([], $result->unknownFlags);
        $this->assertSame('/tmp/repo', $result->root);
        $this->assertFalse($result->help);
        $this->assertFalse($result->promptRequested);
    }

    public function testBareDoubleDashIsNotItselfAnUnknownFlag(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--']);

        $this->assertSame([], $result->unknownFlags);
        $this->assertNull($result->root);
        $this->assertFalse($result->help);
    }

    /**
     * The whole point of the separator: a token that looks exactly like a
     * flag must not be recorded as one, which is what turns the invocation
     * into a usage error in bin/sugarcrush.
     */
    public function testDoubleDashProtectsAFlagLookingOperandFromTheUnknownFlagRecorder(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--', '-weird-looking-path']);

        $this->assertSame([], $result->unknownFlags);
        $this->assertFalse($result->help);
        $this->assertFalse($result->promptRequested);
        // `--` guarantees "not a flag", not "definitely the root": the operand
        // still has to pass the same path-shape heuristic as any positional,
        // and a leading `-` with no separator in it does not.
        $this->assertNull($result->root);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function recognizedFlagsThatMustNotSurviveTheSeparator(): array
    {
        return [
            '--help'          => ['--help'],
            '-h'              => ['-h'],
            '-p'              => ['-p'],
            '--prompt'        => ['--prompt'],
            '--prompt=x'      => ['--prompt=x'],
            '--root=/tmp/x'   => ['--root=/tmp/x'],
            '--output-format' => ['--output-format'],
        ];
    }

    /**
     * Every flag the parser otherwise recognises must be inert after `--`.
     *
     * @dataProvider recognizedFlagsThatMustNotSurviveTheSeparator
     */
    public function testRecognizedFlagsAreInertAfterTheSeparator(string $flag): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--', $flag]);

        $this->assertFalse($result->help, "{$flag} must not still set help");
        $this->assertFalse($result->promptRequested, "{$flag} must not still request a prompt");
        $this->assertNull($result->prompt);
        $this->assertSame(ParsedArgs::DEFAULT_OUTPUT_FORMAT, $result->outputFormat);
        $this->assertSame([], $result->unknownFlags);
    }

    /**
     * `run` is the one non-`-`-prefixed token with flag semantics, and it is
     * only special as the very first argument — which `--` now occupies.
     */
    public function testDoubleDashDisablesTheRunSubcommandAlias(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--', 'run', 'hello']);

        $this->assertFalse($result->promptRequested);
        $this->assertNull($result->prompt);
        $this->assertSame([], $result->unknownFlags);
    }

    /**
     * The separator ends option parsing; it must not retroactively undo the
     * flags that legitimately came before it.
     */
    public function testFlagsBeforeTheSeparatorStillApply(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--output-format=json', '--', '/tmp/repo']);

        $this->assertSame('json', $result->outputFormat);
        $this->assertSame('/tmp/repo', $result->root);
        $this->assertSame([], $result->unknownFlags);
    }

    /**
     * An unknown flag BEFORE the separator is still a usage error — `--`
     * only protects what follows it.
     */
    public function testUnknownFlagBeforeTheSeparatorIsStillRecorded(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--bogus', '--', '/tmp/repo']);

        $this->assertSame(['--bogus'], $result->unknownFlags);
        $this->assertSame('/tmp/repo', $result->root);
    }

    /**
     * A second `--` is just an operand — only the first one is the separator.
     */
    public function testSecondDoubleDashIsAnOrdinaryOperand(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--', '--', '/tmp/repo']);

        $this->assertSame([], $result->unknownFlags);
        $this->assertSame('/tmp/repo', $result->root);
    }

    /**
     * --root before the separator still wins over the operand after it, the
     * same precedence a path-shaped positional has always had.
     */
    public function testExplicitRootFlagStillBeatsAnOperandAfterTheSeparator(): void
    {
        $result = ArgvParser::parse(['sugarcrush', '--root=/tmp/explicit', '--', '/tmp/positional']);

        $this->assertSame('/tmp/explicit', $result->root);
        $this->assertSame([], $result->unknownFlags);
    }

    // -------------------------------------------------------------------------
    // rootError() — a --root that names no directory is a usage error
    // -------------------------------------------------------------------------

    /**
     * The root is not merely a convenience: it reaches every HookContext's
     * projectRoot and, from there, the proc_open() cwd of every ScriptHook.
     * A typo has to be reported, not absorbed (crush_code.md Phase 0 item 6).
     */
    public function testRootErrorReportsARootThatIsNotADirectory(): void
    {
        $missing = sys_get_temp_dir() . '/sugarcrush_no_such_root_' . uniqid('', true);

        $error = ArgvParser::rootError(ArgvParser::parse(['sugarcrush', '--root', $missing]));

        $this->assertNotNull($error);
        $this->assertStringContainsString($missing, $error);
        $this->assertStringContainsString('no such directory', $error);
    }

    public function testRootErrorReportsAFileGivenWhereADirectoryIsExpected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'sugarcrush_root_');
        $this->assertIsString($file);

        try {
            $error = ArgvParser::rootError(ArgvParser::parse(['sugarcrush', '--root=' . $file]));

            $this->assertNotNull($error);
        } finally {
            unlink($file);
        }
    }

    public function testRootErrorIsNullForARootThatExists(): void
    {
        $this->assertNull(ArgvParser::rootError(ArgvParser::parse(['sugarcrush', '--root', sys_get_temp_dir()])));
    }

    /**
     * No `--root` at all is not an error — Bootstrap resolves the default.
     */
    public function testRootErrorIsNullWhenNoRootWasGiven(): void
    {
        $this->assertNull(ArgvParser::rootError(ArgvParser::parse(['sugarcrush'])));
    }

    /**
     * parse() must stay a pure argv -> value-object transform; the filesystem
     * question lives in rootError(), which the binary asks separately.
     */
    public function testParseItselfDoesNotRejectANonExistentRoot(): void
    {
        $missing = sys_get_temp_dir() . '/sugarcrush_no_such_root_' . uniqid('', true);

        $this->assertSame($missing, ArgvParser::parse(['sugarcrush', '--root', $missing])->root);
    }
}
