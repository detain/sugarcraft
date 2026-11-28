<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\Option;
use SugarCraft\Cli\OptionKind;
use SugarCraft\Cli\Tests\Support\Effort;
use SugarCraft\Cli\Tests\Support\Level;
use SugarCraft\Cli\ValueType;

final class OptionTest extends TestCase
{
    public function testFlagFactoryDefaults(): void
    {
        $option = Option::flag('verbose');

        self::assertSame('verbose', $option->name());
        self::assertSame(OptionKind::Flag, $option->kind());
        self::assertFalse($option->takesValue());
        self::assertNull($option->short());
        self::assertNull($option->env());
        self::assertSame('', $option->description());
        self::assertSame(ValueType::String, $option->type());
        self::assertSame([], $option->choices());
        self::assertNull($option->enum());
        self::assertFalse($option->repeatable());
        self::assertFalse($option->required());
        self::assertNull($option->default());
        self::assertSame('', $option->group());
        self::assertSame('VERBOSE', $option->valueName());
        self::assertFalse($option->persistent());
        self::assertSame('--verbose', $option->display());
    }

    public function testValueFactoryTakesValueAndDerivesValueName(): void
    {
        $option = Option::value('api-key');

        self::assertSame(OptionKind::Value, $option->kind());
        self::assertTrue($option->takesValue());
        self::assertSame('API_KEY', $option->valueName());
    }

    /**
     * Each with*() returns a new instance, leaves the original untouched,
     * and the new one reports the value.
     *
     * @return iterable<string, array{\Closure(Option): Option, \Closure(Option): mixed, mixed, mixed}>
     */
    public static function withers(): iterable
    {
        yield 'withShort' => [fn (Option $o) => $o->withShort('m'), fn (Option $o) => $o->short(), null, 'm'];
        yield 'withEnv' => [fn (Option $o) => $o->withEnv('APP_MODEL'), fn (Option $o) => $o->env(), null, 'APP_MODEL'];
        yield 'withDescription' => [fn (Option $o) => $o->withDescription('Model id'), fn (Option $o) => $o->description(), '', 'Model id'];
        yield 'withType' => [fn (Option $o) => $o->withType(ValueType::Int), fn (Option $o) => $o->type(), ValueType::String, ValueType::Int];
        yield 'withChoices' => [fn (Option $o) => $o->withChoices(['a', 'b']), fn (Option $o) => $o->choices(), [], ['a', 'b']];
        yield 'withEnum' => [fn (Option $o) => $o->withEnum(Effort::class), fn (Option $o) => $o->enum(), null, Effort::class];
        yield 'withRepeatable' => [fn (Option $o) => $o->withRepeatable(), fn (Option $o) => $o->repeatable(), false, true];
        yield 'withRequired' => [fn (Option $o) => $o->withRequired(), fn (Option $o) => $o->required(), false, true];
        yield 'withDefault' => [fn (Option $o) => $o->withDefault('gpt'), fn (Option $o) => $o->default(), null, 'gpt'];
        yield 'withGroup' => [fn (Option $o) => $o->withGroup('Model'), fn (Option $o) => $o->group(), '', 'Model'];
        yield 'withValueName' => [fn (Option $o) => $o->withValueName('ID'), fn (Option $o) => $o->valueName(), 'MODEL', 'ID'];
        yield 'withPersistent' => [fn (Option $o) => $o->withPersistent(), fn (Option $o) => $o->persistent(), false, true];
    }

    #[DataProvider('withers')]
    public function testWithReturnsNewInstanceAndLeavesOriginal(\Closure $with, \Closure $read, mixed $before, mixed $after): void
    {
        $original = Option::value('model');
        $changed = $with($original);

        self::assertNotSame($original, $changed);
        self::assertSame($before, $read($original));
        self::assertSame($after, $read($changed));
    }

    public function testWithersCarryOtherFieldsForward(): void
    {
        $option = Option::value('model')->withShort('m')->withEnv('M')->withGroup('G')->withDescription('d');

        self::assertSame('m', $option->short());
        self::assertSame('M', $option->env());
        self::assertSame('G', $option->group());
        self::assertSame('d', $option->description());
    }

    public function testFalseTogglesBack(): void
    {
        $option = Option::flag('x')->withRepeatable()->withRepeatable(false)
            ->withRequired()->withRequired(false)
            ->withPersistent()->withPersistent(false);

        self::assertFalse($option->repeatable());
        self::assertFalse($option->required());
        self::assertFalse($option->persistent());
    }

    public function testWithEnumDerivesChoicesFromBackingValues(): void
    {
        self::assertSame(['low', 'high'], Option::value('effort')->withEnum(Effort::class)->choices());
        self::assertSame(['1', '2'], Option::value('level')->withEnum(Level::class)->choices());
    }

    public function testWithChoicesClearsEnumBinding(): void
    {
        $option = Option::value('effort')->withEnum(Effort::class)->withChoices(['x']);

        self::assertNull($option->enum());
        self::assertSame(['x'], $option->choices());
    }

    /** @return iterable<string, array{string}> */
    public static function badNames(): iterable
    {
        yield 'empty' => [''];
        yield 'leading dash' => ['-model'];
        yield 'space' => ['my model'];
        yield 'equals' => ['a=b'];
    }

    #[DataProvider('badNames')]
    public function testInvalidLongNameThrows(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Option::flag($name);
    }

    /** @return iterable<string, array{string}> */
    public static function badShorts(): iterable
    {
        yield 'empty' => [''];
        yield 'two chars' => ['ab'];
        yield 'dash' => ['-'];
    }

    #[DataProvider('badShorts')]
    public function testInvalidShortThrows(string $short): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Option::flag('x')->withShort($short);
    }

    public function testInvalidEnvNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Option::value('x')->withEnv('1BAD');
    }

    public function testEmptyChoicesThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Option::value('x')->withChoices([]);
    }

    public function testNonEnumClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Option::value('x')->withEnum(\stdClass::class);
    }

    /** @return iterable<string, array{\Closure(Option): Option}> */
    public static function valueOnlyWithers(): iterable
    {
        yield 'withType' => [fn (Option $o) => $o->withType(ValueType::Int)];
        yield 'withChoices' => [fn (Option $o) => $o->withChoices(['a'])];
        yield 'withEnum' => [fn (Option $o) => $o->withEnum(Effort::class)];
    }

    #[DataProvider('valueOnlyWithers')]
    public function testValueOnlyWithersRejectFlags(\Closure $with): void
    {
        $this->expectException(\LogicException::class);
        $with(Option::flag('x'));
    }
}
