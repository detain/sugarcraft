<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\TextInput;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\TextInput\EchoMode;

/**
 * Tests for {@see EchoMode} — how a {@see TextInput} renders its value.
 */
final class EchoModeTest extends TestCase
{
    // =========================================================================
    // Enum cases exist and have expected string values
    // =========================================================================

    public function testNormalCaseExists(): void
    {
        $this->assertSame('normal', EchoMode::Normal->value);
    }

    public function testPasswordCaseExists(): void
    {
        $this->assertSame('password', EchoMode::Password->value);
    }

    public function testNoneCaseExists(): void
    {
        $this->assertSame('none', EchoMode::None->value);
    }

    // =========================================================================
    // All three cases are distinct
    // =========================================================================

    public function testAllCasesAreDistinct(): void
    {
        $cases = EchoMode::cases();
        $this->assertCount(3, $cases);

        $values = array_map(static fn(EchoMode $c): string => $c->value, $cases);
        $this->assertCount(3, array_unique($values));
    }

    // =========================================================================
    // Backed enum — valueOf / tryFrom work
    // =========================================================================

    public function testValueOfReturnsCorrectCase(): void
    {
        $this->assertSame(EchoMode::Normal, EchoMode::from('normal'));
        $this->assertSame(EchoMode::Password, EchoMode::from('password'));
        $this->assertSame(EchoMode::None, EchoMode::from('none'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(EchoMode::tryFrom('invalid'));
        $this->assertNull(EchoMode::tryFrom(''));
    }

    // =========================================================================
    // match exhaustiveness — ensure all cases handled
    // =========================================================================

    public function testMatchCoversAllCases(): void
    {
        $cases = EchoMode::cases();
        foreach ($cases as $case) {
            $result = match (true) {
                $case === EchoMode::Normal => 'normal-case',
                $case === EchoMode::Password => 'password-case',
                $case === EchoMode::None => 'none-case',
            };
            $this->assertNotEmpty($result);
        }
    }
}
