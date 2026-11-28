<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\CommandSpec;
use SugarCraft\Cli\Option;
use SugarCraft\Cli\Tests\Support\Effort;
use SugarCraft\Cli\UsageError;
use SugarCraft\Cli\UsageErrorKind;
use SugarCraft\Cli\ValueType;

/**
 * Every {@see UsageErrorKind} raised through a real parse, with its exact
 * English message pinned.
 */
final class UsageErrorTest extends TestCase
{
    private static function spec(): CommandSpec
    {
        return CommandSpec::new('app')
            ->withOption(Option::value('model')->withShort('m')->withEnv('APP_MODEL'))
            ->withOption(Option::flag('verbose')->withShort('v'))
            ->withOption(Option::value('count')->withType(ValueType::Int)->withEnv('APP_COUNT'))
            ->withOption(Option::value('ratio')->withType(ValueType::Float))
            ->withOption(Option::value('mode')->withChoices(['fast', 'slow'])->withEnv('APP_MODE'))
            ->withOption(Option::value('effort')->withEnum(Effort::class))
            ->withOption(Option::flag('debug')->withEnv('APP_DEBUG'))
            ->withOption(Option::flag('json'))
            ->withOption(Option::flag('plain'))
            ->withOption(Option::value('key'))
            ->withOption(Option::value('cert'))
            ->conflicts('json', 'plain')
            ->requires('key', 'cert')
            ->withSubcommand(CommandSpec::new('run')->withMinArguments(1)->withMaxArguments(2))
            ->withSubcommand(CommandSpec::new('serve')->withMaxArguments(0))
            ->withSubcommand(
                CommandSpec::new('login')->withOption(Option::value('token')->withRequired()->withEnv('APP_TOKEN')),
            )
            ->withSubcommand(CommandSpec::new('logout')->withOption(Option::value('user')->withRequired()))
            ->withSubcommand(CommandSpec::new('one')->withMaxArguments(1));
    }

    /**
     * @return iterable<string, array{list<string>, array<string, string>, UsageErrorKind, string}>
     */
    public static function errors(): iterable
    {
        yield 'unknown long option with suggestion' => [
            ['--modle', 'x'], [], UsageErrorKind::UnknownOption,
            'unknown option "--modle" (did you mean "--model"?)',
        ];
        yield 'unknown long option without suggestion' => [
            ['--zzzzzz'], [], UsageErrorKind::UnknownOption,
            'unknown option "--zzzzzz"',
        ];
        yield 'unknown short option' => [
            ['-vx'], [], UsageErrorKind::UnknownOption,
            'unknown option "-x"',
        ];
        yield 'unknown command with suggestion' => [
            ['rnu'], [], UsageErrorKind::UnknownCommand,
            'unknown command "rnu" for "app" (did you mean "run"?)',
        ];
        yield 'unknown command without suggestion' => [
            ['deploy'], [], UsageErrorKind::UnknownCommand,
            'unknown command "deploy" for "app"',
        ];
        yield 'missing value at end' => [
            ['--model'], [], UsageErrorKind::MissingValue,
            'option "--model" needs a value',
        ];
        yield 'missing value before option' => [
            ['-m', '--verbose'], [], UsageErrorKind::MissingValue,
            'option "--model" needs a value',
        ];
        yield 'missing value before double dash' => [
            ['--model', '--'], [], UsageErrorKind::MissingValue,
            'option "--model" needs a value',
        ];
        yield 'flag given a value' => [
            ['--verbose=yes'], [], UsageErrorKind::UnexpectedValue,
            'option "--verbose" does not take a value',
        ];
        yield 'invalid integer' => [
            ['--count', '1.5'], [], UsageErrorKind::InvalidValue,
            'invalid value "1.5" for option "--count": expected an integer',
        ];
        yield 'invalid float' => [
            ['--ratio=abc'], [], UsageErrorKind::InvalidValue,
            'invalid value "abc" for option "--ratio": expected a number',
        ];
        yield 'invalid integer from env' => [
            [], ['APP_COUNT' => 'many'], UsageErrorKind::InvalidValue,
            'invalid value "many" for option "--count" (from APP_COUNT): expected an integer',
        ];
        yield 'invalid flag word from env' => [
            [], ['APP_DEBUG' => 'maybe'], UsageErrorKind::InvalidValue,
            'invalid value "maybe" for option "--debug" (from APP_DEBUG): expected one of 1, true, yes, on, 0, false, no, off',
        ];
        yield 'invalid choice with suggestion' => [
            ['--mode', 'fsat'], [], UsageErrorKind::InvalidChoice,
            'invalid value "fsat" for option "--mode": expected one of fast, slow (did you mean "fast"?)',
        ];
        yield 'invalid enum value' => [
            ['--effort', 'medium'], [], UsageErrorKind::InvalidChoice,
            'invalid value "medium" for option "--effort": expected one of low, high',
        ];
        yield 'invalid choice from env' => [
            [], ['APP_MODE' => 'warp'], UsageErrorKind::InvalidChoice,
            'invalid value "warp" for option "--mode" (from APP_MODE): expected one of fast, slow',
        ];
        yield 'missing required option with env' => [
            ['login'], [], UsageErrorKind::MissingOption,
            'required option "--token" is missing (or set APP_TOKEN)',
        ];
        yield 'missing required option without env' => [
            ['logout'], [], UsageErrorKind::MissingOption,
            'required option "--user" is missing',
        ];
        yield 'missing requirement' => [
            ['--key', 'k'], [], UsageErrorKind::MissingRequirement,
            'option "--key" requires "--cert"',
        ];
        yield 'conflicting options' => [
            ['--json', '--plain'], [], UsageErrorKind::ConflictingOptions,
            'options "--json" and "--plain" cannot be used together',
        ];
        yield 'too few arguments' => [
            ['run'], [], UsageErrorKind::TooFewArguments,
            '"app run" expects at least 1 argument, got 0',
        ];
        yield 'too many arguments' => [
            ['run', 'a', 'b', 'c'], [], UsageErrorKind::TooManyArguments,
            '"app run" expects at most 2 arguments, got 3',
        ];
        yield 'too many arguments singular' => [
            ['one', 'a', 'b'], [], UsageErrorKind::TooManyArguments,
            '"app one" expects at most 1 argument, got 2',
        ];
        yield 'takes no arguments' => [
            ['serve', 'x'], [], UsageErrorKind::TooManyArguments,
            '"app serve" takes no arguments, got 1',
        ];
        yield 'arguments after double dash on a command with subcommands' => [
            ['--', 'x'], [], UsageErrorKind::TooManyArguments,
            '"app" takes no arguments, got 1',
        ];
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     */
    #[DataProvider('errors')]
    public function testUsageErrorKindAndMessage(array $argv, array $env, UsageErrorKind $kind, string $message): void
    {
        $result = self::spec()->parse($argv, $env);

        self::assertInstanceOf(UsageError::class, $result);
        self::assertSame($kind, $result->kind());
        self::assertSame($message, $result->message());
    }

    public function testTooFewArgumentsPlural(): void
    {
        $result = CommandSpec::new('cp')->withMinArguments(2)->parse(['a'], []);

        self::assertInstanceOf(UsageError::class, $result);
        self::assertSame('"cp" expects at least 2 arguments, got 1', $result->message());
    }

    public function testFirstErrorByArgvPositionWins(): void
    {
        $result = self::spec()->parse(['--count', 'x', '--nope'], []);

        self::assertInstanceOf(UsageError::class, $result);
        self::assertSame(UsageErrorKind::InvalidValue, $result->kind());
    }

    public function testAccessorsExposeStructuredParts(): void
    {
        $result = self::spec()->parse(['--modle'], []);

        self::assertInstanceOf(UsageError::class, $result);
        self::assertSame('--modle', $result->option());
        self::assertSame('--model', $result->suggestion());
        self::assertSame(['app'], $result->command());
        self::assertSame(['option' => '--modle', 'suggestion' => '--model'], $result->params());
    }

    public function testCommandErrorHasNoOption(): void
    {
        $result = self::spec()->parse(['rnu'], []);

        self::assertInstanceOf(UsageError::class, $result);
        self::assertNull($result->option());
        self::assertSame('run', $result->suggestion());
    }

    public function testNewBuildsFromKindParamsAndCommand(): void
    {
        $error = UsageError::new(UsageErrorKind::MissingValue, ['option' => '--x'], ['a', 'b']);

        self::assertSame(UsageErrorKind::MissingValue, $error->kind());
        self::assertSame(['a', 'b'], $error->command());
        self::assertNull($error->suggestion());
        self::assertSame('option "--x" needs a value', $error->message());
    }
}
