<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Vim;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Vim\TextObjectScope;

/**
 * Tests for {@see TextObjectScope} — scope selector for vim text objects (i vs a).
 */
final class TextObjectScopeTest extends TestCase
{
    // =========================================================================
    // Enum cases exist and have expected string values
    // =========================================================================

    public function testInnerCaseExists(): void
    {
        $this->assertSame('inner', TextObjectScope::Inner->value);
    }

    public function testAroundCaseExists(): void
    {
        $this->assertSame('around', TextObjectScope::Around->value);
    }

    // =========================================================================
    // Both cases are distinct
    // =========================================================================

    public function testBothCasesAreDistinct(): void
    {
        $cases = TextObjectScope::cases();
        $this->assertCount(2, $cases);

        $values = array_map(static fn(TextObjectScope $c): string => $c->value, $cases);
        $this->assertCount(2, array_unique($values));
    }

    // =========================================================================
    // fromKey static factory
    // =========================================================================

    public function testFromKeyReturnsInnerForI(): void
    {
        $this->assertSame(TextObjectScope::Inner, TextObjectScope::fromKey('i'));
    }

    public function testFromKeyReturnsAroundForA(): void
    {
        $this->assertSame(TextObjectScope::Around, TextObjectScope::fromKey('a'));
    }

    public function testFromKeyReturnsNullForUnknownKey(): void
    {
        $this->assertNull(TextObjectScope::fromKey('w'));
        $this->assertNull(TextObjectScope::fromKey('I'));
        $this->assertNull(TextObjectScope::fromKey('A'));
        $this->assertNull(TextObjectScope::fromKey(''));
        $this->assertNull(TextObjectScope::fromKey('inner'));
        $this->assertNull(TextObjectScope::fromKey('around'));
    }

    // =========================================================================
    // Backed enum — valueOf / tryFrom work
    // =========================================================================

    public function testValueOfReturnsCorrectCase(): void
    {
        $this->assertSame(TextObjectScope::Inner, TextObjectScope::from('inner'));
        $this->assertSame(TextObjectScope::Around, TextObjectScope::from('around'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(TextObjectScope::tryFrom('i'));
        $this->assertNull(TextObjectScope::tryFrom('a'));
        $this->assertNull(TextObjectScope::tryFrom('INNER')); // case-sensitive
        $this->assertNull(TextObjectScope::tryFrom(''));
    }

    // =========================================================================
    // match exhaustiveness — ensure both cases handled
    // =========================================================================

    public function testMatchCoversAllCases(): void
    {
        $cases = TextObjectScope::cases();
        $results = [];
        foreach ($cases as $case) {
            $results[$case->value] = match (true) {
                $case === TextObjectScope::Inner => 'inner-scope',
                $case === TextObjectScope::Around => 'around-scope',
            };
        }

        $this->assertCount(2, $results);
        $this->assertSame('inner-scope', $results['inner']);
        $this->assertSame('around-scope', $results['around']);
    }
}
