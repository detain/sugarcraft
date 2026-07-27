<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Vim;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Vim\VimOperator;

/**
 * Tests for {@see VimOperator} — vim pending operators that combine with motion/text object.
 */
final class VimOperatorTest extends TestCase
{
    // =========================================================================
    // Enum cases exist and have expected string values
    // =========================================================================

    public function testChangeCaseExists(): void
    {
        $this->assertSame('change', VimOperator::Change->value);
    }

    public function testDeleteCaseExists(): void
    {
        $this->assertSame('delete', VimOperator::Delete->value);
    }

    public function testYankCaseExists(): void
    {
        $this->assertSame('yank', VimOperator::Yank->value);
    }

    // =========================================================================
    // All three cases are distinct
    // =========================================================================

    public function testAllCasesAreDistinct(): void
    {
        $cases = VimOperator::cases();
        $this->assertCount(3, $cases);

        $values = array_map(static fn(VimOperator $c): string => $c->value, $cases);
        $this->assertCount(3, array_unique($values));
    }

    // =========================================================================
    // fromKey static factory
    // =========================================================================

    public function testFromKeyReturnsChangeForC(): void
    {
        $this->assertSame(VimOperator::Change, VimOperator::fromKey('c'));
    }

    public function testFromKeyReturnsDeleteForD(): void
    {
        $this->assertSame(VimOperator::Delete, VimOperator::fromKey('d'));
    }

    public function testFromKeyReturnsYankForY(): void
    {
        $this->assertSame(VimOperator::Yank, VimOperator::fromKey('y'));
    }

    public function testFromKeyReturnsNullForUnknownKey(): void
    {
        $this->assertNull(VimOperator::fromKey('x'));
        $this->assertNull(VimOperator::fromKey('p'));
        $this->assertNull(VimOperator::fromKey('w'));
        $this->assertNull(VimOperator::fromKey(''));
        $this->assertNull(VimOperator::fromKey('C')); // uppercase
    }

    // =========================================================================
    // Backed enum — valueOf / tryFrom work
    // =========================================================================

    public function testValueOfReturnsCorrectCase(): void
    {
        $this->assertSame(VimOperator::Change, VimOperator::from('change'));
        $this->assertSame(VimOperator::Delete, VimOperator::from('delete'));
        $this->assertSame(VimOperator::Yank, VimOperator::from('yank'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(VimOperator::tryFrom('c'));
        $this->assertNull(VimOperator::tryFrom('d'));
        $this->assertNull(VimOperator::tryFrom(''));
        $this->assertNull(VimOperator::tryFrom('CHANGE')); // case-sensitive
    }

    // =========================================================================
    // match exhaustiveness — ensure all cases handled
    // =========================================================================

    public function testMatchCoversAllCases(): void
    {
        $cases = VimOperator::cases();
        $results = [];
        foreach ($cases as $case) {
            $results[$case->value] = match (true) {
                $case === VimOperator::Change => 'change-op',
                $case === VimOperator::Delete => 'delete-op',
                $case === VimOperator::Yank => 'yank-op',
            };
        }

        $this->assertCount(3, $results);
        $this->assertSame('change-op', $results['change']);
        $this->assertSame('delete-op', $results['delete']);
        $this->assertSame('yank-op', $results['yank']);
    }
}
