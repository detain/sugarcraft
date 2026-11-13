<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings\Validator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\SettingValidator;
use SugarCraft\Code\Config\Settings\Validator\SpendCapValidator;

/** The spend-cap validator: unset is fine, anything that cannot cap spend is refused out loud. */
final class SpendCapValidatorTest extends TestCase
{
    public function testItIsASettingValidator(): void
    {
        self::assertInstanceOf(SettingValidator::class, SpendCapValidator::new());
    }

    public function testNullMeansNoCapAndIsAccepted(): void
    {
        self::assertNull(SpendCapValidator::new()->validate(null));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function usable(): iterable
    {
        yield 'int' => [5];
        yield 'float' => [0.01];
        yield 'large' => [1.0e9];
    }

    #[DataProvider('usable')]
    public function testPositiveFiniteNumbersAreAccepted(mixed $value): void
    {
        self::assertNull(SpendCapValidator::new()->validate($value));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function unusable(): iterable
    {
        yield 'zero' => [0];
        yield 'zero float' => [0.0];
        yield 'negative' => [-3];
        yield 'nan' => [NAN];
        yield 'inf' => [INF];
        yield 'numeric string' => ['5'];
        yield 'bool' => [true];
        yield 'array' => [[5]];
    }

    #[DataProvider('unusable')]
    public function testEverythingElseIsRefusedWithTheDollarsMessage(mixed $value): void
    {
        $error = SpendCapValidator::new()->validate($value);

        self::assertNotNull($error);
        self::assertStringContainsString('positive number of US dollars', $error);
    }

    public function testTheSiblingSettingsArgumentIsIgnored(): void
    {
        self::assertNull(SpendCapValidator::new()->validate(2, ['maxCost' => 'junk']));
    }
}
