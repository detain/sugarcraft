<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\CommandSpec;
use SugarCraft\Cli\Option;
use SugarCraft\Cli\ParsedArgs;
use SugarCraft\Cli\Tests\Support\Effort;
use SugarCraft\Cli\Tests\Support\Level;
use SugarCraft\Cli\UsageError;
use SugarCraft\Cli\UsageErrorKind;
use SugarCraft\Cli\ValueSource;
use SugarCraft\Cli\ValueType;

/** One test per parse rule of {@see CommandSpec::parse()}. */
final class CommandSpecParseTest extends TestCase
{
    // ---- option spellings ----

    public function testLongValueSeparateAndInline(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('model'));

        self::assertSame('gpt', $this->ok($spec, ['--model', 'gpt'])->value('model'));
        self::assertSame('gpt', $this->ok($spec, ['--model=gpt'])->value('model'));
        self::assertSame('a=b', $this->ok($spec, ['--model=a=b'])->value('model'));
        self::assertSame('', $this->ok($spec, ['--model='])->value('model'));
    }

    public function testShortValueSeparateAttachedAndEquals(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('model')->withShort('m'));

        self::assertSame('gpt', $this->ok($spec, ['-m', 'gpt'])->value('model'));
        self::assertSame('gpt', $this->ok($spec, ['-mgpt'])->value('model'));
        self::assertSame('gpt', $this->ok($spec, ['-m=gpt'])->value('model'));
    }

    public function testGroupedShortFlagsAndTrailingValueOption(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('all')->withShort('a'))
            ->withOption(Option::flag('brief')->withShort('b'))
            ->withOption(Option::value('model')->withShort('m'));

        $parsed = $this->ok($spec, ['-ab']);
        self::assertTrue($parsed->value('all'));
        self::assertTrue($parsed->value('brief'));
        self::assertNull($parsed->value('model'));

        $parsed = $this->ok($spec, ['-abmgpt']);
        self::assertTrue($parsed->value('all'));
        self::assertSame('gpt', $parsed->value('model'));

        $parsed = $this->ok($spec, ['-am', 'gpt']);
        self::assertSame('gpt', $parsed->value('model'));
    }

    public function testDoubleDashEndsOptions(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::flag('verbose'));

        $parsed = $this->ok($spec, ['--', '--verbose', '-x']);
        self::assertFalse($parsed->value('verbose'));
        self::assertSame(['--verbose', '-x'], $parsed->arguments());
    }

    public function testLoneDashAndNegativeNumbersArePositional(): void
    {
        $spec = CommandSpec::new('app');

        self::assertSame(['-', '-5', '-1.5'], $this->ok($spec, ['-', '-5', '-1.5'])->arguments());
    }

    public function testNegativeNumberIsAcceptedAsAValue(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('offset')->withType(ValueType::Int));

        self::assertSame(-5, $this->ok($spec, ['--offset', '-5'])->value('offset'));
    }

    public function testDigitShortOptionWinsOverNegativeNumber(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::flag('one')->withShort('1'));

        $parsed = $this->ok($spec, ['-1']);
        self::assertTrue($parsed->value('one'));
        self::assertSame([], $parsed->arguments());
    }

    public function testOptionsAndPositionalsInterleave(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::flag('verbose'));

        $parsed = $this->ok($spec, ['a', '--verbose', 'b']);
        self::assertTrue($parsed->value('verbose'));
        self::assertSame(['a', 'b'], $parsed->arguments());
    }

    // ---- typed values and enums ----

    public function testIntAndFloatTypesConvert(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::value('count')->withType(ValueType::Int))
            ->withOption(Option::value('ratio')->withType(ValueType::Float));

        $parsed = $this->ok($spec, ['--count', '+42', '--ratio', '1e3']);
        self::assertSame(42, $parsed->value('count'));
        self::assertSame(1000.0, $parsed->value('ratio'));
    }

    public function testIntAcceptsTheFullIntegerRange(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::value('hi')->withType(ValueType::Int))
            ->withOption(Option::value('lo')->withType(ValueType::Int));

        $parsed = $this->ok($spec, ['--hi', (string) PHP_INT_MAX, '--lo', (string) PHP_INT_MIN]);
        self::assertSame(PHP_INT_MAX, $parsed->value('hi'));
        self::assertSame(PHP_INT_MIN, $parsed->value('lo'));
    }

    public function testChoicesAcceptListedValue(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('mode')->withChoices(['fast', 'slow']));

        self::assertSame('slow', $this->ok($spec, ['--mode', 'slow'])->value('mode'));
    }

    public function testEnumValueResolvesToCase(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::value('effort')->withEnum(Effort::class))
            ->withOption(Option::value('level')->withEnum(Level::class));

        $parsed = $this->ok($spec, ['--effort', 'high', '--level', '2']);
        self::assertSame(Effort::High, $parsed->value('effort'));
        self::assertSame(Level::Two, $parsed->value('level'));
    }

    // ---- repeatables and last-wins ----

    public function testRepeatableValueCollectsList(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('tag')->withShort('t')->withRepeatable());

        self::assertSame(['a', 'b', 'c'], $this->ok($spec, ['--tag', 'a', '-tb', '--tag=c'])->value('tag'));
        self::assertSame([], $this->ok($spec, [])->value('tag'));
    }

    public function testRepeatableFlagCounts(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::flag('verbose')->withShort('v')->withRepeatable());

        self::assertSame(3, $this->ok($spec, ['-vv', '--verbose'])->value('verbose'));
        self::assertSame(0, $this->ok($spec, [])->value('verbose'));
    }

    public function testNonRepeatableValueKeepsLast(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('model'));

        self::assertSame('b', $this->ok($spec, ['--model', 'a', '--model', 'b'])->value('model'));
    }

    // ---- env fallback and defaults ----

    public function testEnvFallbackWhenAbsentFromArgv(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('model')->withEnv('APP_MODEL'));

        $parsed = $this->ok($spec, [], ['APP_MODEL' => 'env-model']);
        self::assertSame('env-model', $parsed->value('model'));
        self::assertSame(ValueSource::Env, $parsed->source('model'));
        self::assertTrue($parsed->has('model'));
    }

    public function testArgvBeatsEnv(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('model')->withEnv('APP_MODEL'));

        $parsed = $this->ok($spec, ['--model', 'cli'], ['APP_MODEL' => 'env-model']);
        self::assertSame('cli', $parsed->value('model'));
        self::assertSame(ValueSource::Argv, $parsed->source('model'));
    }

    public function testEmptyEnvCountsAsUnsetAndDefaultApplies(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('model')->withEnv('APP_MODEL')->withDefault('dflt'));

        $parsed = $this->ok($spec, [], ['APP_MODEL' => '']);
        self::assertSame('dflt', $parsed->value('model'));
        self::assertSame(ValueSource::Default, $parsed->source('model'));
        self::assertFalse($parsed->has('model'));
    }

    public function testEnvValueIsTypedAndRepeatableWrapped(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::value('count')->withType(ValueType::Int)->withEnv('APP_COUNT'))
            ->withOption(Option::value('tag')->withRepeatable()->withEnv('APP_TAG'))
            ->withOption(Option::value('effort')->withEnum(Effort::class)->withEnv('APP_EFFORT'));

        $parsed = $this->ok($spec, [], ['APP_COUNT' => '7', 'APP_TAG' => 'a,b', 'APP_EFFORT' => 'low']);
        self::assertSame(7, $parsed->value('count'));
        self::assertSame(['a,b'], $parsed->value('tag'));
        self::assertSame(Effort::Low, $parsed->value('effort'));
    }

    public function testFlagEnvTruthyAndFalsyWords(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('debug')->withEnv('APP_DEBUG'))
            ->withOption(Option::flag('verbose')->withRepeatable()->withEnv('APP_VERBOSE'));

        foreach (['1', 'true', 'YES', ' on '] as $word) {
            $parsed = $this->ok($spec, [], ['APP_DEBUG' => $word, 'APP_VERBOSE' => $word]);
            self::assertTrue($parsed->value('debug'), $word);
            self::assertSame(1, $parsed->value('verbose'), $word);
        }
        foreach (['0', 'false', 'No', 'off'] as $word) {
            $parsed = $this->ok($spec, [], ['APP_DEBUG' => $word]);
            self::assertFalse($parsed->value('debug'), $word);
            self::assertFalse($parsed->has('debug'), $word);
            self::assertNull($parsed->source('debug'), $word);
        }
    }

    public function testEnvDefaultsToProcessEnvironment(): void
    {
        $name = 'SUGAR_CLI_SPEC_TEST_' . getmypid();
        putenv($name . '=from-process');
        try {
            $spec = CommandSpec::new('app')->withOption(Option::value('model')->withEnv($name));
            self::assertSame('from-process', $this->ok($spec, [], null)->value('model'));
        } finally {
            putenv($name);
        }
    }

    public function testDefaultAndEmptyValuesWhenNothingSupplied(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::value('model')->withDefault('gpt'))
            ->withOption(Option::value('other'))
            ->withOption(Option::flag('json'));

        $parsed = $this->ok($spec, []);
        self::assertSame('gpt', $parsed->value('model'));
        self::assertSame(ValueSource::Default, $parsed->source('model'));
        self::assertNull($parsed->value('other'));
        self::assertNull($parsed->source('other'));
        self::assertFalse($parsed->value('json'));
    }

    // ---- requires and conflicts ----

    public function testRequiresSatisfiedByArgvOrEnv(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::value('key'))
            ->withOption(Option::value('cert')->withEnv('APP_CERT'))
            ->requires('key', 'cert');

        $this->ok($spec, ['--key', 'k', '--cert', 'c']);
        $this->ok($spec, ['--key', 'k'], ['APP_CERT' => 'c']);
        $this->ok($spec, []);
    }

    public function testRequiresIsNotSatisfiedByDefault(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::value('key'))
            ->withOption(Option::value('cert')->withDefault('x'))
            ->requires('key', 'cert');

        self::assertSame(UsageErrorKind::MissingRequirement, $this->usageError($spec, ['--key', 'k'])->kind());
    }

    public function testArgvMemberOfConflictSetSilencesEnvOfTheOthers(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('json')->withEnv('APP_JSON'))
            ->withOption(Option::flag('plain'))
            ->conflicts('json', 'plain');

        $parsed = $this->ok($spec, ['--plain'], ['APP_JSON' => '1']);
        self::assertTrue($parsed->value('plain'));
        self::assertFalse($parsed->value('json'));
    }

    public function testTwoEnvMembersOfConflictSetStillConflict(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('json')->withEnv('APP_JSON'))
            ->withOption(Option::flag('plain')->withEnv('APP_PLAIN'))
            ->conflicts('json', 'plain');

        self::assertSame(
            UsageErrorKind::ConflictingOptions,
            $this->usageError($spec, [], ['APP_JSON' => '1', 'APP_PLAIN' => '1'])->kind(),
        );
    }

    public function testConflictReportsOptionsInArgvOrder(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('json'))
            ->withOption(Option::flag('plain'))
            ->conflicts('json', 'plain');

        $error = $this->usageError($spec, ['--plain', '--json']);
        self::assertSame('--plain', $error->params()['option']);
        self::assertSame('--json', $error->params()['other']);
    }

    public function testRequiredSatisfiedByEnv(): void
    {
        $spec = CommandSpec::new('app')->withOption(Option::value('model')->withRequired()->withEnv('APP_MODEL'));

        self::assertSame('m', $this->ok($spec, [], ['APP_MODEL' => 'm'])->value('model'));
    }

    // ---- subcommands ----

    public function testSubcommandSelectsAndParsesItsOwnOptions(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('verbose'))
            ->withSubcommand(CommandSpec::new('run')->withOption(Option::value('count'))->withMaxArguments(null));

        $parsed = $this->ok($spec, ['--verbose', 'run', '--count', '2', 'file']);
        self::assertSame(['app', 'run'], $parsed->command());
        self::assertTrue($parsed->value('verbose'));
        self::assertSame('2', $parsed->value('count'));
        self::assertSame(['file'], $parsed->arguments());
    }

    public function testNestedSubcommands(): void
    {
        $spec = CommandSpec::new('git')->withSubcommand(
            CommandSpec::new('remote')->withSubcommand(CommandSpec::new('add')->withMinArguments(2)->withMaxArguments(2)),
        );

        $parsed = $this->ok($spec, ['remote', 'add', 'origin', 'url']);
        self::assertSame(['git', 'remote', 'add'], $parsed->command());
        self::assertSame(['origin', 'url'], $parsed->arguments());
    }

    public function testNoSubcommandGivenStaysOnRoot(): void
    {
        $spec = CommandSpec::new('app')->withSubcommand(CommandSpec::new('run'));

        self::assertSame(['app'], $this->ok($spec, [])->command());
    }

    public function testPersistentOptionIsAcceptedAfterSubcommand(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('verbose')->withShort('v')->withPersistent())
            ->withSubcommand(CommandSpec::new('run'));

        self::assertTrue($this->ok($spec, ['run', '-v'])->value('verbose'));
    }

    public function testNonPersistentParentOptionIsUnknownAfterSubcommand(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('json'))
            ->withSubcommand(CommandSpec::new('run'));

        $error = $this->usageError($spec, ['run', '--json']);
        self::assertSame(UsageErrorKind::UnknownOption, $error->kind());
        self::assertSame(['app', 'run'], $error->command());
    }

    public function testCommandWithSubcommandsAndArgumentsTakesUnknownWordAsArgument(): void
    {
        $spec = CommandSpec::new('app')->withSubcommand(CommandSpec::new('run'))->withMaxArguments(1);

        $parsed = $this->ok($spec, ['file']);
        self::assertSame(['app'], $parsed->command());
        self::assertSame(['file'], $parsed->arguments());
    }

    public function testSubcommandNameAfterAnArgumentIsAnArgument(): void
    {
        $spec = CommandSpec::new('app')->withSubcommand(CommandSpec::new('run'))->withMaxArguments(null);

        $parsed = $this->ok($spec, ['file', 'run']);
        self::assertSame(['app'], $parsed->command());
        self::assertSame(['file', 'run'], $parsed->arguments());
    }

    public function testArgumentBoundsAccepted(): void
    {
        $spec = CommandSpec::new('app')->withMinArguments(1)->withMaxArguments(2);

        self::assertSame(['a'], $this->ok($spec, ['a'])->arguments());
        self::assertSame(['a', 'b'], $this->ok($spec, ['a', 'b'])->arguments());
    }

    /**
     * @param list<string> $argv
     * @param array<string, string>|null $env
     */
    private function ok(CommandSpec $spec, array $argv, ?array $env = []): ParsedArgs
    {
        $result = $spec->parse($argv, $env);
        self::assertInstanceOf(ParsedArgs::class, $result, $result instanceof UsageError ? $result->message() : '');
        return $result;
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     */
    private function usageError(CommandSpec $spec, array $argv, array $env = []): UsageError
    {
        $result = $spec->parse($argv, $env);
        self::assertInstanceOf(UsageError::class, $result);
        return $result;
    }
}
