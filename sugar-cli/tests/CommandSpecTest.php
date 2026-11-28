<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\CommandSpec;
use SugarCraft\Cli\Option;

/** Builder, accessor and spec-validation coverage; parse rules live in CommandSpecParseTest. */
final class CommandSpecTest extends TestCase
{
    public function testNewDefaults(): void
    {
        $spec = CommandSpec::new('app');

        self::assertSame('app', $spec->name());
        self::assertSame('', $spec->description());
        self::assertSame([], $spec->options());
        self::assertSame([], $spec->subcommands());
        self::assertSame([], $spec->conflictSets());
        self::assertSame([], $spec->requirements());
        self::assertSame(0, $spec->minArguments());
        self::assertNull($spec->maxArguments());
        self::assertNull($spec->effectiveMaxArguments());
        self::assertSame([], $spec->groups());
    }

    /**
     * @return iterable<string, array{\Closure(CommandSpec): CommandSpec, \Closure(CommandSpec): mixed}>
     */
    public static function builders(): iterable
    {
        yield 'withDescription' => [
            fn (CommandSpec $s) => $s->withDescription('An app'),
            fn (CommandSpec $s) => $s->description(),
        ];
        yield 'withOption' => [
            fn (CommandSpec $s) => $s->withOption(Option::flag('x')),
            fn (CommandSpec $s) => array_keys($s->options()),
        ];
        yield 'withSubcommand' => [
            fn (CommandSpec $s) => $s->withSubcommand(CommandSpec::new('run')),
            fn (CommandSpec $s) => array_keys($s->subcommands()),
        ];
        yield 'conflicts' => [
            fn (CommandSpec $s) => $s->conflicts('a', 'b'),
            fn (CommandSpec $s) => $s->conflictSets(),
        ];
        yield 'requires' => [
            fn (CommandSpec $s) => $s->requires('a', 'b'),
            fn (CommandSpec $s) => $s->requirements(),
        ];
        yield 'withMinArguments' => [
            fn (CommandSpec $s) => $s->withMinArguments(2),
            fn (CommandSpec $s) => $s->minArguments(),
        ];
        yield 'withMaxArguments' => [
            fn (CommandSpec $s) => $s->withMaxArguments(3),
            fn (CommandSpec $s) => $s->maxArguments(),
        ];
    }

    #[DataProvider('builders')]
    public function testBuilderReturnsNewInstanceAndLeavesOriginal(\Closure $build, \Closure $read): void
    {
        $original = CommandSpec::new('app');
        $before = $read($original);
        $changed = $build($original);

        self::assertNotSame($original, $changed);
        self::assertSame($before, $read($original));
        self::assertNotSame($before, $read($changed));
    }

    public function testAccessorsReportWhatWasBuilt(): void
    {
        $model = Option::value('model')->withGroup('Model');
        $run = CommandSpec::new('run');
        $spec = CommandSpec::new('app')
            ->withOption($model)
            ->withOption(Option::flag('json'))
            ->withOption(Option::flag('plain'))
            ->withOption(Option::value('temp')->withGroup('Model'))
            ->withSubcommand($run)
            ->conflicts('json', 'plain', 'json')
            ->requires('temp', 'model')
            ->requires('temp', 'model', 'json')
            ->withMinArguments(1)
            ->withMaxArguments(2);

        self::assertSame($model, $spec->option('model'));
        self::assertNull($spec->option('nope'));
        self::assertSame($run, $spec->subcommand('run'));
        self::assertNull($spec->subcommand('nope'));
        self::assertSame([['json', 'plain']], $spec->conflictSets());
        self::assertSame(['temp' => ['model', 'json']], $spec->requirements());
        self::assertSame(1, $spec->minArguments());
        self::assertSame(2, $spec->maxArguments());
        self::assertSame(2, $spec->effectiveMaxArguments());
    }

    public function testGroupsKeepFirstAppearanceOrderWithUngroupedUnderEmptyKey(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::value('model')->withGroup('Model'))
            ->withOption(Option::flag('json'))
            ->withOption(Option::value('temp')->withGroup('Model'))
            ->withOption(Option::flag('debug')->withGroup('Debug'));

        $groups = array_map(
            static fn (array $options): array => array_map(static fn (Option $o): string => $o->name(), $options),
            $spec->groups(),
        );

        self::assertSame(['Model' => ['model', 'temp'], '' => ['json'], 'Debug' => ['debug']], $groups);
    }

    public function testAnAllDigitGroupNameComesBackAsAnIntKey(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('a')->withGroup('2024'))
            ->withOption(Option::flag('b'));

        $groups = $spec->groups();

        self::assertSame([2024, ''], array_keys($groups));
        self::assertSame('2024', (string) array_key_first($groups));
        self::assertSame('2024', $groups[2024][0]->group());
    }

    public function testEffectiveMaxIsZeroWithSubcommandsUnlessSetEvenToNull(): void
    {
        $spec = CommandSpec::new('app')->withSubcommand(CommandSpec::new('run'));

        self::assertSame(0, $spec->effectiveMaxArguments());
        self::assertNull($spec->withMaxArguments(null)->effectiveMaxArguments());
        self::assertNull($spec->withMaxArguments(null)->maxArguments());
        self::assertSame(5, $spec->withMaxArguments(5)->effectiveMaxArguments());
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'leading dash' => ['-app'];
        yield 'space' => ['my app'];
        yield 'trailing newline' => ["app\n"];
    }

    #[DataProvider('badNames')]
    public function testInvalidCommandNameThrows(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::new($name);
    }

    public function testDuplicateLongOptionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::new('app')->withOption(Option::flag('x'))->withOption(Option::value('x'));
    }

    public function testDuplicateShortOptionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::new('app')
            ->withOption(Option::flag('x')->withShort('x'))
            ->withOption(Option::flag('y')->withShort('x'));
    }

    public function testDuplicateSubcommandThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::new('app')->withSubcommand(CommandSpec::new('run'))->withSubcommand(CommandSpec::new('run'));
    }

    public function testConflictSetNeedsTwoDistinctNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::new('app')->conflicts('a', 'a');
    }

    public function testRequiresNeedsATarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::new('app')->requires('a');
    }

    public function testNegativeMinThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::new('app')->withMinArguments(-1);
    }

    public function testNegativeMaxThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CommandSpec::new('app')->withMaxArguments(-1);
    }

    public function testParseRejectsRuleNamingUndeclaredOption(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('undeclared option "--b"');
        CommandSpec::new('app')->withOption(Option::flag('a'))->conflicts('a', 'b')->parse([], []);
    }

    public function testMalformedSpecThrowsEvenWhenArgvIsAlsoBad(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('undeclared option "--ghost"');
        CommandSpec::new('x')->withOption(Option::flag('a'))->conflicts('a', 'ghost')->parse(['--a', '--nope'], []);
    }

    public function testMalformedSubcommandThrowsEvenWhenItIsNotSelected(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('force'))
            ->withSubcommand(CommandSpec::new('run')->withOption(Option::flag('force')));

        $this->expectException(\LogicException::class);
        $spec->parse([], []);
    }

    public function testParseRejectsRequirementNamingUndeclaredOption(): void
    {
        $this->expectException(\LogicException::class);
        CommandSpec::new('app')->withOption(Option::flag('a'))->requires('a', 'missing')->parse([], []);
    }

    public function testParseRejectsMinAboveMax(): void
    {
        $this->expectException(\LogicException::class);
        CommandSpec::new('app')->withMinArguments(3)->withMaxArguments(1)->parse([], []);
    }

    public function testParseRejectsMinAboveImplicitMaxOfACommandWithSubcommands(): void
    {
        // No withMaxArguments(): the subcommands make the effective max 0,
        // so a minimum of 1 can never be met and is a spec error.
        $spec = CommandSpec::new('tool')->withMinArguments(1)->withSubcommand(CommandSpec::new('run'));

        $this->expectException(\LogicException::class);
        $spec->parse([], []);
    }

    public function testExplicitUnboundedMaxKeepsAMinimumOnACommandWithSubcommandsValid(): void
    {
        $spec = CommandSpec::new('tool')
            ->withMinArguments(1)
            ->withMaxArguments(null)
            ->withSubcommand(CommandSpec::new('run'));

        self::assertSame(['x'], $spec->parse(['--', 'x'], [])->arguments());
    }

    public function testAllDigitNamesComeBackAsIntKeysAndStringNames(): void
    {
        $spec = CommandSpec::new('t')
            ->withOption(Option::flag('5'))
            ->withSubcommand(CommandSpec::new('42'));

        // PHP casts all-digit keys to int; the documented contract is
        // array-key, and the string name lives on the value object.
        self::assertSame([5], array_keys($spec->options()));
        self::assertSame('5', $spec->options()[5]->name());
        self::assertSame([42], array_keys($spec->subcommands()));
        self::assertSame('42', $spec->subcommands()[42]->name());
        $parsed = CommandSpec::new('t')->withOption(Option::flag('5'))->parse(['--5'], []);
        self::assertSame([5 => true], $parsed->values());
        self::assertTrue($parsed->value('5'));
    }

    public function testParseRejectsPersistentNameRepeatedInSubcommand(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('verbose')->withPersistent())
            ->withSubcommand(CommandSpec::new('run')->withOption(Option::flag('verbose')));

        $this->expectException(\LogicException::class);
        $spec->parse(['run'], []);
    }

    public function testParseRejectsPersistentShortRepeatedInSubcommand(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('verbose')->withShort('v')->withPersistent())
            ->withSubcommand(CommandSpec::new('run')->withOption(Option::flag('version')->withShort('v')));

        $this->expectException(\LogicException::class);
        $spec->parse(['run'], []);
    }

    public function testParseRejectsNonPersistentNameRepeatedAlongPath(): void
    {
        $spec = CommandSpec::new('app')
            ->withOption(Option::flag('force'))
            ->withSubcommand(CommandSpec::new('run')->withOption(Option::flag('force')));

        $this->expectException(\LogicException::class);
        $spec->parse(['run'], []);
    }
}
