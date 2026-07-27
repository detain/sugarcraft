<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\Action;
use SugarCraft\Ansi\Parser\State;
use PHPUnit\Framework\TestCase;

final class StateEnumTest extends TestCase
{
    public function testAllStatesHaveIntegerValues(): void
    {
        foreach (State::cases() as $case) {
            $this->assertIsInt($case->value);
            $this->assertGreaterThanOrEqual(0, $case->value);
        }
    }

    public function testAllStateValuesAreUnique(): void
    {
        $values = array_map(fn (State $s) => $s->value, State::cases());
        $this->assertSame(count($values), count(array_unique($values)), 'State values must be unique');
    }

    public function testGroundStateIsZero(): void
    {
        $this->assertSame(0, State::Ground->value);
    }

    public function testStateCountIs15(): void
    {
        $this->assertCount(15, State::cases());
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(State::tryFrom(999));
        $this->assertNull(State::tryFrom(-1));
    }

    public function testTryFromReturnsCaseForValidValue(): void
    {
        $this->assertSame(State::Ground, State::tryFrom(0));
        $this->assertSame(State::CsiEntry, State::tryFrom(1));
        $this->assertSame(State::Utf8, State::tryFrom(14));
    }

    /**
     * @dataProvider stateCaseProvider
     */
    public function testStateFromValueRoundTrips(State $expected): void
    {
        $this->assertSame($expected, State::from($expected->value));
    }

    /**
     * @return list<array{State}>
     */
    public static function stateCaseProvider(): array
    {
        return array_map(fn (State $s) => [$s], State::cases());
    }
}
