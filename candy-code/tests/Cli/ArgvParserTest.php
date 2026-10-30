<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\ParsedArgs;
use SugarCraft\Code\Cli\Subcommands;

/**
 * Tests for {@see ArgvParser} — argv parsing for candy-code CLI.
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
        $result = ArgvParser::parse(['candy-code']);

        $this->assertFalse($result->help);
    }

    public function testDashHEnablesHelp(): void
    {
        $result = ArgvParser::parse(['candy-code', '-h']);

        $this->assertTrue($result->help);
    }

    public function testDashHelpEnablesHelp(): void
    {
        $result = ArgvParser::parse(['candy-code', '--help']);

        $this->assertTrue($result->help);
    }

    // -------------------------------------------------------------------------
    // Prompt flag
    // -------------------------------------------------------------------------

    public function testPromptDefaultsToNull(): void
    {
        $result = ArgvParser::parse(['candy-code']);

        $this->assertNull($result->prompt);
    }

    public function testDashPSetsPrompt(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'write a test']);

        $this->assertSame('write a test', $result->prompt);
    }

    public function testLongPromptWithSpaceSetsPrompt(): void
    {
        $result = ArgvParser::parse(['candy-code', '--prompt', 'explain this code']);

        $this->assertSame('explain this code', $result->prompt);
    }

    public function testLongPromptWithEqualsSetsPrompt(): void
    {
        $result = ArgvParser::parse(['candy-code', '--prompt=hello world']);

        $this->assertSame('hello world', $result->prompt);
    }

    // -------------------------------------------------------------------------
    // --output-format
    // -------------------------------------------------------------------------

    public function testOutputFormatDefaultsToText(): void
    {
        $result = ArgvParser::parse(['candy-code']);

        $this->assertSame('text', $result->outputFormat);
        $this->assertSame(ParsedArgs::DEFAULT_OUTPUT_FORMAT, $result->outputFormat);
    }

    public function testOutputFormatWithSpaceSetsOutputFormat(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'x', '--output-format', 'json']);

        $this->assertSame('json', $result->outputFormat);
    }

    public function testOutputFormatWithEqualsSetsOutputFormat(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'x', '--output-format=json']);

        $this->assertSame('json', $result->outputFormat);
    }

    // -------------------------------------------------------------------------
    // Root path extraction
    // -------------------------------------------------------------------------

    public function testRootDefaultsToNull(): void
    {
        $result = ArgvParser::parse(['candy-code']);

        $this->assertNull($result->root);
    }

    public function testAbsolutePathPositionalBecomesRoot(): void
    {
        $result = ArgvParser::parse(['candy-code', '/home/user/myproject']);

        $this->assertSame('/home/user/myproject', $result->root);
    }

    public function testRelativePathPositionalBecomesRoot(): void
    {
        $result = ArgvParser::parse(['candy-code', './myproject']);

        $this->assertSame('./myproject', $result->root);
    }

    public function testPathWithSeparatorBecomesRoot(): void
    {
        $result = ArgvParser::parse(['candy-code', 'src/commands']);

        $this->assertSame('src/commands', $result->root);
    }

    public function testNonPathPositionalDoesNotBecomeRoot(): void
    {
        $result = ArgvParser::parse(['candy-code', 'hello']);

        $this->assertNull($result->root);
    }

    public function testPromptBeforeRootDoesNotConsumeRoot(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'fix the bug', '/code/project']);

        $this->assertSame('fix the bug', $result->prompt);
        $this->assertSame('/code/project', $result->root);
    }

    public function testRootFlagOverridesPositionalPath(): void
    {
        $result = ArgvParser::parse(['candy-code', '/wrong/path', '--root', '/correct/path']);

        $this->assertSame('/correct/path', $result->root);
    }

    public function testRootEqualsFlagOverridesPositionalPath(): void
    {
        $result = ArgvParser::parse(['candy-code', '/wrong/path', '--root=/correct/path']);

        $this->assertSame('/correct/path', $result->root);
    }

    // -------------------------------------------------------------------------
    // Unknown flags — recorded, never applied (crush_code.md Phase 0 item 3;
    // bin/candy-code turns a non-empty $unknownFlags into exit 2)
    // -------------------------------------------------------------------------

    public function testUnknownFlagIsRecordedAndNotApplied(): void
    {
        // Should not throw, should not set any field
        $result = ArgvParser::parse(['candy-code', '--unknown-flag', 'value']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertNull($result->root);
        $this->assertSame(['--unknown-flag'], $result->unknownFlags);
    }

    public function testUnknownShortFlagIsRecordedAndNotApplied(): void
    {
        $result = ArgvParser::parse(['candy-code', '-z']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertNull($result->root);
        $this->assertSame(['-z'], $result->unknownFlags);
    }

    public function testUnknownFlagWithValueIsRecordedAndNotApplied(): void
    {
        // -z without a following argument advances the index, so we just
        // verify no exception is thrown and defaults remain.
        $result = ArgvParser::parse(['candy-code', '-z', 'value']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertSame(['-z'], $result->unknownFlags);
    }

    public function testUnknownFlagDoesNotAffectKnownFlags(): void
    {
        $result = ArgvParser::parse(['candy-code', '--unknown', '-p', 'hello', '--also-unknown']);

        $this->assertSame('hello', $result->prompt);
        $this->assertFalse($result->help);
        $this->assertSame(['--unknown', '--also-unknown'], $result->unknownFlags);
    }

    /**
     * `--version` used to be this vector's first entry — it was the original
     * reproduction for the unknown-flag guard. It is a real flag now
     * (crush_code.md Phase 4 item 3), so an equally-unknown long flag stands in
     * for it and the ordering assertion is unchanged.
     */
    public function testEveryUnknownFlagIsRecordedInOrder(): void
    {
        $result = ArgvParser::parse(['candy-code', '--verzion', '-z', '--nope']);

        $this->assertSame(['--verzion', '-z', '--nope'], $result->unknownFlags);
    }

    public function testRecognisedInvocationsRecordNoUnknownFlags(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'hello', '--output-format=json', '--root=/tmp/x', '-h']);

        $this->assertSame([], $result->unknownFlags);
    }

    /**
     * C-CTX-4.g: `--append-system-prompt` is recognised in both spellings —
     * an unrecognised flag lands in the unknown-flags record (which bin turns
     * into exit 2), and a silently-unconsumed append would leave the operator
     * rules out of the prompt while the launch still ran.
     */
    public function testAppendSystemPromptIsRecognisedInBothSpellings(): void
    {
        $spaced = ArgvParser::parse(['candy-code', '-p', 'x', '--append-system-prompt', 'be terse']);
        $equals = ArgvParser::parse(['candy-code', '--append-system-prompt=@rules.md', '-p', 'x']);

        $this->assertSame([], $spaced->unknownFlags);
        $this->assertSame([], $equals->unknownFlags);
    }

    /**
     * `-px` is one unknown token, NOT "-p" plus a clustered "x" — the parser
     * does no short-flag clustering. "hello" then survives as a positional
     * that is not path-shaped, so it is discarded; before Phase 0 item 3 that
     * left an empty ParsedArgs and `candy-code -px "hello"` opened the TUI.
     */
    public function testClusteredShortFlagIsTreatedAsOneUnknownFlag(): void
    {
        $result = ArgvParser::parse(['candy-code', '-px', 'hello']);

        $this->assertSame(['-px'], $result->unknownFlags);
        $this->assertNull($result->prompt);
        $this->assertFalse($result->promptRequested);
        $this->assertNull($result->root);
    }

    // -------------------------------------------------------------------------
    // promptRequested — "one-shot mode was asked for", value or not. This is
    // what bin/candy-code dispatches on, so a bare `-p` reaches
    // NonInteractive::run()'s "no prompt given" error instead of the TUI.
    // -------------------------------------------------------------------------

    public function testPromptRequestedDefaultsToFalse(): void
    {
        $result = ArgvParser::parse(['candy-code']);

        $this->assertFalse($result->promptRequested);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string|null}>
     */
    public static function promptRequestingForms(): array
    {
        return [
            'bare -p'            => [['candy-code', '-p'], null],
            'bare --prompt'      => [['candy-code', '--prompt'], null],
            'bare run'           => [['candy-code', 'run'], null],
            'empty --prompt='    => [['candy-code', '--prompt='], ''],
            '-p with value'      => [['candy-code', '-p', 'hi'], 'hi'],
            '--prompt with value' => [['candy-code', '--prompt', 'hi'], 'hi'],
            '--prompt= value'    => [['candy-code', '--prompt=hi'], 'hi'],
            'run with value'     => [['candy-code', 'run', 'hi'], 'hi'],
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
        $result = ArgvParser::parse(['candy-code', '--output-format=json', '--root=/tmp/x']);

        $this->assertFalse($result->promptRequested);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string|null}>
     */
    public static function runSubcommandPositions(): array
    {
        return [
            'first argument'            => [['candy-code', 'run', 'hi'], 'hi'],
            'after --root='             => [['candy-code', '--root=/tmp/x', 'run', 'hi'], 'hi'],
            'after --root <value>'      => [['candy-code', '--root', '/tmp/x', 'run', 'hi'], 'hi'],
            'after --output-format'     => [['candy-code', '--output-format', 'json', 'run', 'hi'], 'hi'],
            'after --output-format='    => [['candy-code', '--output-format=json', 'run', 'hi'], 'hi'],
            'after a path positional'   => [['candy-code', '/tmp/x', 'run', 'hi'], 'hi'],
            'with no prompt value'      => [['candy-code', '--output-format=json', 'run'], null],
        ];
    }

    /**
     * `run` is the subcommand alias wherever a bare operand can legitimately
     * appear, not only at $argv[1].
     *
     * Pinning it to the first position meant any flag placed before it silently
     * discarded the subcommand, leaving promptRequested false — so
     * `candy-code --output-format json run` fell through bin/candy-code's
     * dispatch into the blocking full-screen TUI. Same hang class as the
     * `--version` fall-through (crush_code.md Phase 0 item 3), reopened by
     * argument order alone.
     *
     * @param list<string> $argv
     *
     * @dataProvider runSubcommandPositions
     */
    public function testRunIsRecognizedAtAnyOperandPosition(array $argv, ?string $expectedPrompt): void
    {
        $result = ArgvParser::parse($argv);

        $this->assertTrue($result->promptRequested, 'the subcommand was discarded, so bin/candy-code would open the TUI');
        $this->assertSame($expectedPrompt, $result->prompt);
        $this->assertSame([], $result->unknownFlags);
    }

    /**
     * The two readings that must NOT become subcommands, kept from the
     * original first-position-only rule: `run` supplied as a flag's VALUE is
     * consumed by that flag and never reaches the subcommand test, and a
     * second `run` after one-shot mode is already armed is an ordinary
     * positional rather than a re-arm.
     *
     * @return array<string, array{0: list<string>, 1: string|null}>
     */
    public static function runAsAValueNotASubcommand(): array
    {
        return [
            '-p run'          => [['candy-code', '-p', 'run'], 'run'],
            '--prompt run'    => [['candy-code', '--prompt', 'run'], 'run'],
            '--prompt=run'    => [['candy-code', '--prompt=run'], 'run'],
            '--root run'      => [['candy-code', '--root', 'run', '-p', 'hi'], 'hi'],
            'run run'         => [['candy-code', 'run', 'run'], 'run'],
            '-p hi then run'  => [['candy-code', '-p', 'hi', 'run'], 'hi'],
        ];
    }

    /**
     * @param list<string> $argv
     *
     * @dataProvider runAsAValueNotASubcommand
     */
    public function testRunAsAFlagValueStaysAValue(array $argv, ?string $expectedPrompt): void
    {
        $result = ArgvParser::parse($argv);

        $this->assertSame($expectedPrompt, $result->prompt);
        $this->assertSame([], $result->unknownFlags);
    }

    public function testRunSuppliedAsARootValueDoesNotRequestAPrompt(): void
    {
        $result = ArgvParser::parse(['candy-code', '--root', 'run']);

        $this->assertFalse($result->promptRequested);
        $this->assertSame('run', $result->root);
    }

    // -------------------------------------------------------------------------
    // Combined / edge cases
    // -------------------------------------------------------------------------

    public function testHelpWithPromptAndRoot(): void
    {
        $result = ArgvParser::parse(['candy-code', '--help', '-p', 'hello', '/path']);

        $this->assertTrue($result->help);
        $this->assertSame('hello', $result->prompt);
        $this->assertSame('/path', $result->root);
    }

    public function testEmptyArgvReturnsDefaults(): void
    {
        // $argv with only script name
        $result = ArgvParser::parse(['candy-code']);

        $this->assertFalse($result->help);
        $this->assertNull($result->prompt);
        $this->assertNull($result->root);
    }

    /**
     * Inverted from what it originally pinned. This used to assert that `-p`
     * consumes a following FLAG as its value ("mirrors getopt behaviour in
     * pop") — but nothing in getopt does that, and no caller typing
     * `candy-code -p --verbose` meant to send the four characters "--verbose"
     * to a model. It is a usage error now (follow-up #48); see
     * {@see self::flagShapedPromptValues()} for the full matrix.
     */
    public function testPromptValueIsNotTheNextFlag(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', '--verbose']);

        $this->assertNull($result->prompt);
        $this->assertNotNull($result->usageError);
    }

    // -------------------------------------------------------------------------
    // `--` end-of-options separator
    // -------------------------------------------------------------------------

    /**
     * `candy-code -- /tmp/repo` is the one invocation shape that used to do
     * something (set root, open the TUI) and then started exiting 2, because
     * `--` had no handling at all and fell into the unknown-flag recorder.
     */
    public function testDoubleDashIsConsumedAndTheOperandAfterItBecomesRoot(): void
    {
        $result = ArgvParser::parse(['candy-code', '--', '/tmp/repo']);

        $this->assertSame([], $result->unknownFlags);
        $this->assertSame('/tmp/repo', $result->root);
        $this->assertFalse($result->help);
        $this->assertFalse($result->promptRequested);
    }

    public function testBareDoubleDashIsNotItselfAnUnknownFlag(): void
    {
        $result = ArgvParser::parse(['candy-code', '--']);

        $this->assertSame([], $result->unknownFlags);
        $this->assertNull($result->root);
        $this->assertFalse($result->help);
    }

    /**
     * The whole point of the separator: a token that looks exactly like a
     * flag must not be recorded as one, which is what turns the invocation
     * into a usage error in bin/candy-code.
     */
    public function testDoubleDashProtectsAFlagLookingOperandFromTheUnknownFlagRecorder(): void
    {
        $result = ArgvParser::parse(['candy-code', '--', '-weird-looking-path']);

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
            '--worktree'      => ['--worktree'],
            '--worktree=x'    => ['--worktree=x'],
        ];
    }

    /**
     * Every flag the parser otherwise recognises must be inert after `--`.
     *
     * @dataProvider recognizedFlagsThatMustNotSurviveTheSeparator
     */
    public function testRecognizedFlagsAreInertAfterTheSeparator(string $flag): void
    {
        $result = ArgvParser::parse(['candy-code', '--', $flag]);

        $this->assertFalse($result->help, "{$flag} must not still set help");
        $this->assertFalse($result->promptRequested, "{$flag} must not still request a prompt");
        $this->assertNull($result->prompt);
        $this->assertSame(ParsedArgs::DEFAULT_OUTPUT_FORMAT, $result->outputFormat);
        $this->assertSame([], $result->unknownFlags);
    }

    /**
     * `run` is the one non-`-`-prefixed token with flag semantics, so the
     * separator has to neutralise it like any other. This matters more now
     * that `run` is recognised at any operand position rather than only at
     * $argv[1]: `--` is the ONLY thing left that turns it back into a plain
     * operand.
     */
    public function testDoubleDashDisablesTheRunSubcommandAlias(): void
    {
        $result = ArgvParser::parse(['candy-code', '--', 'run', 'hello']);

        $this->assertFalse($result->promptRequested);
        $this->assertNull($result->prompt);
        $this->assertSame([], $result->unknownFlags);
    }

    /**
     * The same, with a flag before the separator — the shape that would have
     * regressed if the position rule had simply been dropped rather than
     * moved below the two `--` branches.
     */
    public function testDoubleDashDisablesRunEvenWhenFlagsPrecedeIt(): void
    {
        $result = ArgvParser::parse(['candy-code', '--output-format=json', '--', 'run', 'hello']);

        $this->assertFalse($result->promptRequested);
        $this->assertNull($result->prompt);
        $this->assertSame('json', $result->outputFormat);
    }

    /**
     * The separator ends option parsing; it must not retroactively undo the
     * flags that legitimately came before it.
     */
    public function testFlagsBeforeTheSeparatorStillApply(): void
    {
        $result = ArgvParser::parse(['candy-code', '--output-format=json', '--', '/tmp/repo']);

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
        $result = ArgvParser::parse(['candy-code', '--bogus', '--', '/tmp/repo']);

        $this->assertSame(['--bogus'], $result->unknownFlags);
        $this->assertSame('/tmp/repo', $result->root);
    }

    /**
     * A second `--` is just an operand — only the first one is the separator.
     * Being the FIRST operand, it is also what the root rule looks at, so the
     * path after it is an ordinary operand too (audit CLI-2(b): only the first
     * operand can name the root).
     */
    public function testSecondDoubleDashIsAnOrdinaryOperand(): void
    {
        $result = ArgvParser::parse(['candy-code', '--', '--', '/tmp/repo']);

        $this->assertSame([], $result->unknownFlags);
        $this->assertNull($result->root);
        $this->assertSame(['--', '/tmp/repo'], $result->positionals);
    }

    /**
     * --root before the separator still wins over the operand after it, the
     * same precedence a path-shaped positional has always had.
     */
    public function testExplicitRootFlagStillBeatsAnOperandAfterTheSeparator(): void
    {
        $result = ArgvParser::parse(['candy-code', '--root=/tmp/explicit', '--', '/tmp/positional']);

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
        $missing = sys_get_temp_dir() . '/candy_code_no_such_root_' . uniqid('', true);

        $error = ArgvParser::rootError(ArgvParser::parse(['candy-code', '--root', $missing]));

        $this->assertNotNull($error);
        $this->assertStringContainsString($missing, $error);
        $this->assertStringContainsString('no such directory', $error);
    }

    public function testRootErrorReportsAFileGivenWhereADirectoryIsExpected(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'candy_code_root_');
        $this->assertIsString($file);

        try {
            $error = ArgvParser::rootError(ArgvParser::parse(['candy-code', '--root=' . $file]));

            $this->assertNotNull($error);
        } finally {
            unlink($file);
        }
    }

    public function testRootErrorIsNullForARootThatExists(): void
    {
        $this->assertNull(ArgvParser::rootError(ArgvParser::parse(['candy-code', '--root', sys_get_temp_dir()])));
    }

    /**
     * No `--root` at all is not an error — Bootstrap resolves the default.
     */
    public function testRootErrorIsNullWhenNoRootWasGiven(): void
    {
        $this->assertNull(ArgvParser::rootError(ArgvParser::parse(['candy-code'])));
    }

    /**
     * parse() must stay a pure argv -> value-object transform; the filesystem
     * question lives in rootError(), which the binary asks separately.
     */
    public function testParseItselfDoesNotRejectANonExistentRoot(): void
    {
        $missing = sys_get_temp_dir() . '/candy_code_no_such_root_' . uniqid('', true);

        $this->assertSame($missing, ArgvParser::parse(['candy-code', '--root', $missing])->root);
    }

    // -------------------------------------------------------------------------
    // --version / -v (crush_code.md Phase 4 item 3)
    // -------------------------------------------------------------------------

    public function testVersionDefaultsToFalse(): void
    {
        $this->assertFalse(ArgvParser::parse(['candy-code'])->version);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function versionFlags(): array
    {
        return ['long' => ['--version'], 'short' => ['-v']];
    }

    /**
     * @dataProvider versionFlags
     */
    public function testVersionFlagIsRecognisedAndNotAnUnknownFlag(string $flag): void
    {
        $result = ArgvParser::parse(['candy-code', $flag]);

        $this->assertTrue($result->version);
        $this->assertSame([], $result->unknownFlags, 'the flag fell through to the unknown-flag recorder');
        $this->assertFalse($result->help);
        $this->assertFalse($result->promptRequested);
    }

    /**
     * `--` protects what follows it from being read as a flag, so
     * `candy-code -- --version` is a (non-path-shaped, therefore discarded)
     * operand and the binary opens the TUI — the same contract `--help` and
     * every other flag already honour.
     */
    public function testVersionAfterTheSeparatorIsAnOperandNotAFlag(): void
    {
        $result = ArgvParser::parse(['candy-code', '--', '--version']);

        $this->assertFalse($result->version);
        $this->assertSame([], $result->unknownFlags);
    }

    // -------------------------------------------------------------------------
    // A prompt option handed a flag is a usage error (follow-up #48)
    // -------------------------------------------------------------------------

    public function testUsageErrorDefaultsToNull(): void
    {
        $this->assertNull(ArgvParser::parse(['candy-code', '-p', 'hello'])->usageError);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function flagShapedPromptValues(): array
    {
        return [
            '-p'          => [['candy-code', '-p', '--verbose'], '-p'],
            '--prompt'    => [['candy-code', '--prompt', '--verbose'], '--prompt'],
            'run'         => [['candy-code', 'run', '--verbose'], 'run'],
            // The end-of-options separator is flag-shaped too: swallowing it as
            // prompt text is the same silent misreading.
            '-p then --'  => [['candy-code', '-p', '--'], '-p'],
            'short flag'  => [['candy-code', '-p', '-z'], '-p'],
        ];
    }

    /**
     * @param list<string> $argv
     *
     * @dataProvider flagShapedPromptValues
     */
    public function testAFlagShapedPromptValueIsAUsageErrorAndNotThePrompt(array $argv, string $option): void
    {
        $result = ArgvParser::parse($argv);

        $this->assertNotNull($result->usageError, 'the flag was silently accepted as the prompt');
        $this->assertStringContainsString($option, $result->usageError, 'the offending option must be named');
        $this->assertStringContainsString($argv[2], $result->usageError, 'the offending value must be named');
        $this->assertNull($result->prompt);
    }

    /**
     * The escape hatch: the `=` form takes its value from the same token, so
     * it cannot be ambiguous and a leading dash stays literal.
     */
    public function testTheEqualsFormStillAcceptsAPromptBeginningWithADash(): void
    {
        $result = ArgvParser::parse(['candy-code', '--prompt=--verbose']);

        $this->assertNull($result->usageError);
        $this->assertSame('--verbose', $result->prompt);
        $this->assertTrue($result->promptRequested);
    }

    /**
     * A lone `-` is the POSIX stdin/stdout placeholder, not an option — a
     * caller who types it meant it, so it stays a legal prompt.
     */
    public function testALoneDashIsStillAcceptedAsAPrompt(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', '-']);

        $this->assertNull($result->usageError);
        $this->assertSame('-', $result->prompt);
    }

    /**
     * The rejected token is left unconsumed so the loop still classifies it —
     * which is what lets the binary report the sharper usage error first and
     * still name the flag if the user asked for `--help` in the same breath.
     */
    public function testTheRejectedValueIsStillClassifiedByTheRestOfTheParse(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', '--help']);

        $this->assertNotNull($result->usageError);
        $this->assertTrue($result->help);
    }

    /**
     * Only the FIRST such mistake is reported, so the message is deterministic
     * rather than a function of how many options the caller got wrong.
     */
    public function testOnlyTheFirstFlagShapedPromptValueIsReported(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', '--verbose', '--prompt', '--quiet']);

        $this->assertNotNull($result->usageError);
        $this->assertStringContainsString('--verbose', $result->usageError);
        $this->assertStringNotContainsString('--quiet', $result->usageError);
    }

    // -------------------------------------------------------------------------
    // --output-format validation (crush_code.md Phase 4 item 6)
    // -------------------------------------------------------------------------

    /**
     * The measured hole: every consumer of $outputFormat tests
     * `=== NonInteractive::FORMAT_JSON` and renders text otherwise, so before
     * this check `--output-format xml` was byte-for-byte identical to
     * `--output-format text` AND exited 0 — a `| jq` caller got a silent empty
     * pipe with a success status.
     */
    public function testAnUnsupportedOutputFormatIsAUsageError(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'hi', '--output-format', 'xml']);

        $this->assertNotNull($result->usageError);
        $this->assertStringContainsString('--output-format xml', $result->usageError);
        $this->assertNotNull($result->usageHint);
        $this->assertStringContainsString('text', $result->usageHint);
        $this->assertStringContainsString('json', $result->usageHint);
    }

    /**
     * The `=`-form takes the same path — a check that only covered the spaced
     * spelling would leave half the hole open.
     */
    public function testAnUnsupportedOutputFormatIsAUsageErrorInTheEqualsForm(): void
    {
        $result = ArgvParser::parse(['candy-code', '--output-format=jsonl', '-p', 'hi']);

        $this->assertNotNull($result->usageError);
        $this->assertStringContainsString('jsonl', $result->usageError);
    }

    /**
     * The check runs whether or not one-shot mode was asked for, so the TUI
     * path rejects the same vector the one-shot path does. Without it
     * `candy-code --output-format xml` opened the alt-screen, because the TUI
     * never looks at $outputFormat at all.
     */
    public function testTheOutputFormatCheckAlsoFiresWithoutAPromptRequest(): void
    {
        $result = ArgvParser::parse(['candy-code', '--output-format', 'xml']);

        $this->assertFalse($result->promptRequested);
        $this->assertNotNull($result->usageError);
    }

    /**
     * CASE-SENSITIVE, decided deliberately: the consumers compare with `===`,
     * so accepting `JSON` without rewriting the stored value would re-open the
     * hole (a validated format that still prints text), and rewriting it would
     * silently change what `--output-format JSON` has always done.
     */
    public function testOutputFormatMatchingIsCaseSensitive(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'hi', '--output-format', 'JSON']);

        $this->assertNotNull($result->usageError);
        $this->assertStringContainsString('JSON', $result->usageError);
    }

    /**
     * ISS-084: `candy-code -p hi --output-format` used to keep `text` and
     * exit 0 — the silent-empty-pipe failure this flag's validation exists to
     * prevent, arriving by omission instead of by typo. It is now the
     * `cli.argv.*.missing` family's own error, naming the flag.
     */
    public function testATrailingOutputFormatWithoutAValueIsAUsageError(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'hi', '--output-format']);

        $this->assertNotNull($result->usageError, 'the missing value was silently accepted as text');
        $this->assertStringContainsString('--output-format', $result->usageError);
        $this->assertStringContainsString('argument list ended', $result->usageError);
        $this->assertNotNull($result->usageHint);
        $this->assertStringContainsString('--output-format=', $result->usageHint);
        $this->assertSame('text', $result->outputFormat, 'a rejected value must not stain the format');
    }

    /**
     * A flag-shaped value is refused like `--root`/`--config` refuse theirs,
     * and the token stays unconsumed so the rest of the parse still
     * classifies it — `--output-format --verbose` names the offending option
     * AND records `--verbose` as the unknown flag it is.
     */
    public function testAFlagShapedOutputFormatValueIsRefusedAndLeftForTheLoop(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'hi', '--output-format', '--verbose']);

        $this->assertNotNull($result->usageError, 'the flag was silently accepted as the format');
        $this->assertStringContainsString('--output-format', $result->usageError);
        $this->assertStringContainsString('--verbose', $result->usageError);
        $this->assertContains('--verbose', $result->unknownFlags);
        $this->assertSame('text', $result->outputFormat);
    }

    /**
     * The escape hatch: the `=` form takes its value from the same token, so a
     * leading dash stays literal and is never mistaken for a refusal of the
     * missing/flag-shaped kind. `-json` is still no format anybody implements —
     * the error that comes back is the UNSUPPORTED one, naming the literal,
     * which is what proves the token was carried and not swallowed.
     */
    public function testTheEqualsFormCarriesADashShapedValueIntoTheFormatCheck(): void
    {
        $result = ArgvParser::parse(['candy-code', '--output-format=-json']);

        $this->assertNotNull($result->usageError);
        $this->assertStringContainsString('-json', $result->usageError);
        $this->assertStringNotContainsString('argument list ended', $result->usageError);
    }

    /**
     * @dataProvider supportedOutputFormats
     */
    public function testEverySupportedOutputFormatParsesWithoutAUsageError(string $format): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'hi', '--output-format', $format]);

        $this->assertNull($result->usageError);
        $this->assertSame($format, $result->outputFormat);
    }

    /** @return array<string, array{0: string}> */
    public static function supportedOutputFormats(): array
    {
        // ParsedArgs is declared INSIDE ArgvParser.php, so PSR-4 cannot
        // autoload it by name — and a data provider runs before any test has
        // called ArgvParser and pulled the file in. Naming ArgvParser first is
        // what loads both.
        \class_exists(ArgvParser::class);

        $cases = [];
        foreach (ParsedArgs::OUTPUT_FORMATS as $format) {
            $cases[$format] = [$format];
        }

        return $cases;
    }

    /** The absent flag must not trip the new check. */
    public function testTheDefaultOutputFormatIsNotAUsageError(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', 'hi']);

        $this->assertNull($result->usageError);
        $this->assertSame(ParsedArgs::DEFAULT_OUTPUT_FORMAT, $result->outputFormat);
    }

    /**
     * ParsedArgs restates NonInteractive's three format constants as literals to
     * stay free of a compile-time dependency on it; this is the seam that
     * keeps the two copies honest, the same job
     * {@see self::testOutputFormatDefaultsToText()} does for the default.
     */
    public function testOutputFormatsMirrorNonInteractiveConstants(): void
    {
        $this->assertSame(
            [\SugarCraft\Code\Cli\NonInteractive::FORMAT_TEXT, \SugarCraft\Code\Cli\NonInteractive::FORMAT_JSON, \SugarCraft\Code\Cli\NonInteractive::FORMAT_STREAM_JSON],
            ParsedArgs::OUTPUT_FORMATS,
        );
    }

    /**
     * Two usage errors with two different remedies now share one field, so the
     * hint has to travel WITH the error: the binary used to print the
     * "--prompt=<text>" line under every usage failure.
     */
    public function testTheFlagShapedPromptErrorCarriesThePromptHintNotTheFormatHint(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', '--verbose']);

        $this->assertNotNull($result->usageHint);
        $this->assertStringContainsString('--prompt=', $result->usageHint);
    }

    /**
     * A prompt mistake and a format mistake in one vector report the prompt
     * one, matching the existing "only the first is reported" rule — and the
     * hint that comes with it is the prompt hint.
     */
    public function testAPromptErrorWinsOverAnOutputFormatError(): void
    {
        $result = ArgvParser::parse(['candy-code', '-p', '--verbose', '--output-format', 'xml']);

        $this->assertNotNull($result->usageError);
        $this->assertStringContainsString('--verbose', $result->usageError);
        $this->assertStringContainsString('--prompt=', (string) $result->usageHint);
    }

    // -------------------------------------------------------------------------
    // --config (crush_code.md Phase 4 item 6)
    // -------------------------------------------------------------------------

    public function testConfigPathIsNullWhenTheFlagIsAbsent(): void
    {
        $this->assertNull(ArgvParser::parse(['candy-code'])->configPath);
    }

    public function testConfigFlagWithASeparateValue(): void
    {
        $result = ArgvParser::parse(['candy-code', '--config', '/etc/candy-code.json']);

        $this->assertSame('/etc/candy-code.json', $result->configPath);
    }

    public function testConfigFlagWithAnInlineValue(): void
    {
        $result = ArgvParser::parse(['candy-code', '--config=/etc/candy-code.json']);

        $this->assertSame('/etc/candy-code.json', $result->configPath);
    }

    /**
     * `--config` is a recognised flag, so it must not also land in
     * $unknownFlags — which is what would happen if only one of the two
     * spellings had a branch.
     */
    public function testNeitherConfigSpellingIsRecordedAsAnUnknownFlag(): void
    {
        $this->assertSame([], ArgvParser::parse(['candy-code', '--config', '/x.json'])->unknownFlags);
        $this->assertSame([], ArgvParser::parse(['candy-code', '--config=/x.json'])->unknownFlags);
    }

    /** After `--` nothing is a flag, `--config` included. */
    public function testConfigAfterTheEndOfOptionsSeparatorIsPositional(): void
    {
        $result = ArgvParser::parse(['candy-code', '--', '--config', '/x.json']);

        $this->assertNull($result->configPath);
    }

    public function testConfigErrorIsNullWhenNoConfigWasNamed(): void
    {
        $this->assertNull(ArgvParser::configError(ArgvParser::parse(['candy-code'])));
    }

    public function testConfigErrorNamesAFileThatDoesNotExist(): void
    {
        $missing = \sys_get_temp_dir() . '/argv_config_absent_' . \uniqid('', true) . '.json';
        $error = ArgvParser::configError(ArgvParser::parse(['candy-code', '--config', $missing]));

        $this->assertNotNull($error);
        $this->assertStringContainsString($missing, $error);
        $this->assertStringContainsString('no such file', $error);
    }

    /** A directory is the same mistake as an absent path, and reports as one. */
    public function testConfigErrorRejectsADirectory(): void
    {
        $dir = \sys_get_temp_dir() . '/argv_config_dir_' . \uniqid('', true);
        \mkdir($dir, 0700, true);

        try {
            $error = ArgvParser::configError(ArgvParser::parse(['candy-code', '--config', $dir]));
            $this->assertNotNull($error);
            $this->assertStringContainsString('no such file', $error);
        } finally {
            @\rmdir($dir);
        }
    }

    public function testConfigErrorIsNullForAReadableFile(): void
    {
        $file = \sys_get_temp_dir() . '/argv_config_ok_' . \uniqid('', true) . '.json';
        \file_put_contents($file, '{}');

        try {
            $this->assertNull(ArgvParser::configError(ArgvParser::parse(['candy-code', '--config', $file])));
        } finally {
            @\unlink($file);
        }
    }

    /**
     * A present-but-unreadable file is a different message from an absent one,
     * because it is a different fix. Skipped when the suite runs as a user
     * that ignores the mode bits (root), where the premise cannot hold.
     */
    public function testConfigErrorReportsAnUnreadableFileSeparately(): void
    {
        $file = \sys_get_temp_dir() . '/argv_config_unreadable_' . \uniqid('', true) . '.json';
        \file_put_contents($file, '{}');
        \chmod($file, 0000);

        try {
            if (\is_readable($file)) {
                $this->markTestSkipped('this user can read a 0000 file (root); the premise does not hold');
            }

            $error = ArgvParser::configError(ArgvParser::parse(['candy-code', '--config', $file]));
            $this->assertNotNull($error);
            $this->assertStringContainsString('not readable', $error);
        } finally {
            @\chmod($file, 0600);
            @\unlink($file);
        }
    }

    /**
     * `candy-code --config` with nothing after it stored null, and null is how
     * the parser spells "the flag was absent" — so configError() passed it,
     * Bootstrap kept its own discovery, and the run applied the DEFAULT
     * permission policy on an invocation that explicitly named a file. On the
     * TUI path it did that from inside the alt-screen (measured before the
     * fix: rc 124 under a 15s bound, stdout beginning \e[?1049h).
     */
    public function testAConfigWithNoValueIsAUsageError(): void
    {
        $args = ArgvParser::parse(['candy-code', '--config']);

        $this->assertNotNull($args->usageError);
        $this->assertStringContainsString('--config', $args->usageError);
        $this->assertNull($args->configPath, 'a value-less --config must not look like an absent flag');
    }

    /**
     * The hint under it is the one this error earns, not the prompt escape
     * hatch — the defect $usageHint was introduced to fix, one error later.
     */
    public function testTheConfigValueErrorCarriesItsOwnHint(): void
    {
        $args = ArgvParser::parse(['candy-code', '--config']);

        $this->assertNotNull($args->usageHint);
        $this->assertStringNotContainsString('--prompt=', $args->usageHint);
        $this->assertStringContainsString('--config=', $args->usageHint);
    }

    /**
     * `--config -p hi` consumed `-p` as the file name and lost the prompt with
     * it — the misreading -p/--prompt/run already refuse, on the option that
     * takes a path.
     */
    public function testAConfigFollowedByAnOptionIsAUsageErrorAndKeepsThePrompt(): void
    {
        $args = ArgvParser::parse(['candy-code', '--config', '-p', 'hi']);

        $this->assertNotNull($args->usageError);
        $this->assertStringContainsString('-p', $args->usageError);
        $this->assertNull($args->configPath);
        $this->assertSame('hi', $args->prompt, 'the prompt was eaten as the config path');
    }

    /**
     * ...and the flag it did not eat is still parsed, so the error it caused
     * is rendered in the format the caller asked for rather than as text.
     */
    public function testAConfigFollowedByAnOptionDoesNotSwallowThatOption(): void
    {
        $args = ArgvParser::parse(['candy-code', '--config', '--output-format', 'json', '-p', 'hi']);

        $this->assertNotNull($args->usageError);
        $this->assertSame('json', $args->outputFormat);
        $this->assertSame([], $args->unknownFlags, '--output-format was consumed as a value and then reported as unknown');
    }

    /**
     * The equals form is the one spelling that reaches configError() with a
     * path that is not a path. It must be an ERROR rather than absence: '' as
     * an override resolves to no config at all, i.e. an empty policy on a run
     * that asked for a named one.
     */
    public function testAnEmptyConfigValueIsAUsageError(): void
    {
        $args = ArgvParser::parse(['candy-code', '--config=']);

        $this->assertSame('', $args->configPath);

        $error = ArgvParser::configError($args);
        $this->assertNotNull($error);
        $this->assertStringContainsString('empty', $error);
    }

    // -------------------------------------------------------------------------
    // crush_code.md Phase 4 item 6: the real subcommands, at the PARSE layer.
    // The end-to-end routing is exercised against the real binary in
    // Tests\Integration\BinCandyCodeDispatchTest; these pin the reading
    // decisions that class cannot see (which token became the verb, which became
    // its operand, and what did NOT become either).
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: list<string>, 1: string, 2: list<string>}>
     */
    public static function subcommandParses(): array
    {
        return [
            'doctor'             => [['doctor'], 'doctor', []],
            'models'             => [['models'], 'models', []],
            'session list'       => [['session', 'list'], 'session', ['list']],
            'session delete id'  => [['session', 'delete', 'abc123'], 'session', ['delete', 'abc123']],
            'mcp list'           => [['mcp', 'list'], 'mcp', ['list']],
            'completion zsh'     => [['completion', 'zsh'], 'completion', ['zsh']],
            // Behind a flag, in both --output-format spellings: pinning the verb
            // to $argv[1] is what made `run` fall through into the TUI.
            'behind a flag'      => [['--output-format', 'json', 'doctor'], 'doctor', []],
            'behind =flag'       => [['--output-format=json', 'mcp', 'list'], 'mcp', ['list']],
            'behind --root'      => [['--root', '/tmp', 'session', 'list'], 'session', ['list']],
        ];
    }

    /**
     * @param list<string> $argv
     * @param list<string> $expectedArgs
     *
     * @dataProvider subcommandParses
     */
    public function testSubcommandsAndTheirOperandsAreParsed(array $argv, string $verb, array $expectedArgs): void
    {
        $args = ArgvParser::parse(['candy-code', ...$argv]);

        $this->assertSame($verb, $args->subcommand);
        $this->assertSame($expectedArgs, $args->subcommandArgs);
        $this->assertSame([], $args->unknownFlags);
        $this->assertNull($args->usageError);
        $this->assertFalse($args->promptRequested, 'a subcommand must not look like a one-shot run');
    }

    /**
     * A subcommand's OPERANDS must not also be offered to the root heuristic:
     * one token cannot mean both "the id to delete" and "the project root".
     * `/tmp` is path-shaped, so before the routing existed it would have become
     * the root as well.
     */
    public function testASubcommandOperandDoesNotAlsoSetTheRoot(): void
    {
        $args = ArgvParser::parse(['candy-code', 'session', 'delete', '/tmp']);

        $this->assertSame('session', $args->subcommand);
        $this->assertSame(['delete', '/tmp'], $args->subcommandArgs);
        $this->assertNull($args->root, 'a subcommand operand leaked into the root heuristic');
    }

    /**
     * ...while an explicit --root still applies, because it is a flag rather
     * than an operand. This is what lets `candy-code --root <dir> mcp list`
     * inspect another checkout.
     */
    public function testAnExplicitRootStillAppliesBesideASubcommand(): void
    {
        $args = ArgvParser::parse(['candy-code', '--root', '/tmp', 'mcp', 'list']);

        $this->assertSame('/tmp', $args->root);
        $this->assertSame('mcp', $args->subcommand);
        $this->assertSame(['list'], $args->subcommandArgs);
    }

    /**
     * A SECOND verb is data, not a re-arm. `completion doctor` asks for a shell
     * named "doctor" — which Subcommands rejects with a message naming it —
     * rather than silently becoming a health check.
     */
    public function testASecondVerbIsAnOperandOfTheFirst(): void
    {
        $args = ArgvParser::parse(['candy-code', 'completion', 'doctor']);

        $this->assertSame('completion', $args->subcommand);
        $this->assertSame(['doctor'], $args->subcommandArgs);
    }

    /**
     * `run` keeps its own reading: it consumes the next token as a PROMPT
     * inline, so a subcommand verb after it is prompt text and never reaches
     * the verb branch. The two features must not fight over the same token.
     */
    public function testRunStillClaimsAFollowingVerbAsItsPrompt(): void
    {
        $args = ArgvParser::parse(['candy-code', 'run', 'models']);

        $this->assertNull($args->subcommand);
        $this->assertTrue($args->promptRequested);
        $this->assertSame('models', $args->prompt);
    }

    /**
     * ...and the reverse: a `run` AFTER a verb is that verb's operand, not a
     * one-shot prompt. Without the `$subcommand === null` guard on the `run`
     * branch this parsed to promptRequested=true and lost the subcommand.
     */
    public function testRunAfterAVerbIsThatVerbsOperand(): void
    {
        $args = ArgvParser::parse(['candy-code', 'session', 'run']);

        $this->assertSame('session', $args->subcommand);
        $this->assertSame(['run'], $args->subcommandArgs);
        $this->assertFalse($args->promptRequested);
    }

    /**
     * `--` moves a verb out of subcommand space entirely, exactly as it already
     * does for `run` — the separator's whole contract is "everything after this
     * is an operand".
     */
    public function testTheSeparatorDemotesAVerbToAPlainOperand(): void
    {
        $args = ArgvParser::parse(['candy-code', '--', 'doctor']);

        $this->assertNull($args->subcommand);
        $this->assertSame([], $args->subcommandArgs);
        $this->assertSame([], $args->unknownFlags);
    }

    /**
     * ...but once a verb IS open, `--` protects that verb's own operand: an id
     * beginning with a dash has no other spelling, and sending it to
     * $positional would have thrown it away and then complained the id was
     * missing.
     */
    public function testTheSeparatorAfterAVerbProtectsThatVerbsOperand(): void
    {
        $args = ArgvParser::parse(['candy-code', 'session', 'delete', '--', '-dash-id']);

        $this->assertSame('session', $args->subcommand);
        $this->assertSame(['delete', '-dash-id'], $args->subcommandArgs);
        $this->assertSame([], $args->unknownFlags, 'the id was judged as a flag');
    }

    /**
     * A plain TUI or one-shot run is unchanged: no verb, no operands. This is
     * the assertion that would fail if a common word were added to
     * ParsedArgs::SUBCOMMANDS and started swallowing positionals.
     */
    public function testAPlainRunHasNoSubcommand(): void
    {
        foreach ([['-p', 'hello'], ['/tmp'], []] as $argv) {
            $args = ArgvParser::parse(['candy-code', ...$argv]);

            $this->assertNull($args->subcommand, \implode(' ', $argv));
            $this->assertSame([], $args->subcommandArgs, \implode(' ', $argv));
        }
    }

    /**
     * A prompt request SWALLOWS a later verb — `candy-code -p hi models` is a
     * one-shot prompt of "hi", not a request for the provider table.
     *
     * This is the `!$promptRequested` guard, and only the `run models`
     * spelling was pinned: `run` consumes its value inline, so its token never
     * reaches the verb branch at all and the guard is dead for it. The -p and
     * --prompt= spellings DO reach it. MEASURED with the guard removed:
     * `candy-code -p hi models` prints the provider table at exit 0 and the
     * prompt is never run.
     */
    public function testAPromptRequestSwallowsALaterVerb(): void
    {
        foreach ([
            [['-p', 'hi', 'models'], 'hi'],
            [['--prompt=hi', 'doctor'], 'hi'],
            [['-p', 'hi', 'session', 'list'], 'hi'],
            [['--prompt', 'hi', 'completion', 'bash'], 'hi'],
        ] as [$argv, $prompt]) {
            $args = ArgvParser::parse(['candy-code', ...$argv]);
            $label = \implode(' ', $argv);

            $this->assertNull($args->subcommand, $label . ' re-armed as a subcommand');
            $this->assertSame([], $args->subcommandArgs, $label);
            $this->assertTrue($args->promptRequested, $label);
            $this->assertSame($prompt, $args->prompt, $label);
        }
    }

    /**
     * Every verb the parser recognises is one Subcommands can execute, and vice
     * versa. The two lists live in different classes and nothing else compares
     * them, so a verb added to one and forgotten in the other would parse and
     * then hit `dispatch()`'s unreachable default.
     */
    public function testEveryRecognisedVerbIsDispatchable(): void
    {
        foreach (ParsedArgs::SUBCOMMANDS as $verb) {
            $args = ArgvParser::parse(['candy-code', $verb]);
            $this->assertSame($verb, $args->subcommand);
        }

        $reflection = new \ReflectionClass(Subcommands::class);
        $source = (string) \file_get_contents((string) $reflection->getFileName());
        foreach (ParsedArgs::SUBCOMMANDS as $verb) {
            $this->assertStringContainsString(
                "'" . $verb . "' ",
                $source,
                'Subcommands::dispatch() has no arm for ' . $verb,
            );
        }
    }

    /**
     * C-SES-7.c: `--incognito` is a bare switch — no value form, and absent
     * it keeps the saving default byte-identically. The flag rides its own
     * field so every consumer reads one decision, not a sniffed store.
     */
    public function testIncognitoIsABareSwitchCarriedOnTheParsedArgs(): void
    {
        $result = ArgvParser::parse(['candy-code', '--incognito']);

        $this->assertNull($result->usageError);
        $this->assertTrue($result->incognito);
        $this->assertSame([], $result->unknownFlags);

        $plain = ArgvParser::parse(['candy-code']);
        $this->assertFalse($plain->incognito, 'without the flag the launch saves, as always');
    }

    /**
     * `--continue`/`--resume` reopen KEPT sessions; `--incognito` promises
     * nothing is kept. The contradiction is refused at parse time — every
     * reopen spelling, each with the hint that names the flag it tells you
     * to drop (a hint that does not fit the error is the bug this file's
     * prompt/format pair exists to red).
     */
    public function testIncognitoRefusesEveryReopenSpellingWithItsOwnHint(): void
    {
        foreach ([['-c'], ['--continue'], ['--resume', 'abc123'], ['--resume=abc123'], ['--resume']] as $extra) {
            $result = ArgvParser::parse(array_merge(['candy-code', '--incognito'], $extra));

            $this->assertNotNull($result->usageError, implode(' ', $extra) . ' beside --incognito must be refused');
            $this->assertStringContainsString('--incognito', (string) $result->usageError);
            $this->assertStringContainsString('--incognito', (string) $result->usageHint);
        }
    }

    /**
     * The attached-value form is NOT the switch: like every bare flag that
     * never claimed a value slot, `--incognito=x` reaches the unknown-flag
     * list untouched rather than silently meaning "on".
     */
    public function testIncognitoTakesNoAttachedValue(): void
    {
        $result = ArgvParser::parse(['candy-code', '--incognito=now']);

        $this->assertFalse($result->incognito);
        $this->assertContains('--incognito=now', $result->unknownFlags);
    }
}
