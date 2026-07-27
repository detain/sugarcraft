<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\Action;
use PHPUnit\Framework\TestCase;

final class ActionEnumTest extends TestCase
{
    public function testAllActionsHaveIntegerValues(): void
    {
        foreach (Action::cases() as $case) {
            $this->assertIsInt($case->value);
            $this->assertGreaterThanOrEqual(0, $case->value);
        }
    }

    public function testAllActionValuesAreUnique(): void
    {
        $values = array_map(fn (Action $a) => $a->value, Action::cases());
        $this->assertSame(count($values), count(array_unique($values)), 'Action values must be unique');
    }

    public function testNoneIsZero(): void
    {
        $this->assertSame(0, Action::None->value);
    }

    public function testActionCountIs10(): void
    {
        $this->assertCount(10, Action::cases());
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(Action::tryFrom(99));
        $this->assertNull(Action::tryFrom(-1));
    }

    public function testTryFromReturnsCaseForValidValue(): void
    {
        $this->assertSame(Action::None, Action::tryFrom(0));
        $this->assertSame(Action::Print, Action::tryFrom(9));
    }

    /**
     * @dataProvider actionCaseProvider
     */
    public function testActionFromValueRoundTrips(Action $expected): void
    {
        $this->assertSame($expected, Action::from($expected->value));
    }

    /**
     * @return list<array{Action}>
     */
    public static function actionCaseProvider(): array
    {
        return array_map(fn (Action $a) => [$a], Action::cases());
    }

    public function testActionValuesRangeFrom0To9(): void
    {
        $values = array_map(fn (Action $a) => $a->value, Action::cases());
        $this->assertSame(0, min($values));
        $this->assertSame(9, max($values));
    }
}
