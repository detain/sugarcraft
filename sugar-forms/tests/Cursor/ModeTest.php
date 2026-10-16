<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Cursor;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Cursor\Mode;

/**
 * Tests for {@see Mode} — how a {@see Cursor} renders the cell under it.
 */
final class ModeTest extends TestCase
{
    // =========================================================================
    // Enum cases exist and have expected string values
    // =========================================================================

    public function testBlinkCaseExists(): void
    {
        $this->assertSame('blink', Mode::Blink->value);
    }

    public function testStaticCaseExists(): void
    {
        $this->assertSame('static', Mode::Static->value);
    }

    public function testHiddenCaseExists(): void
    {
        $this->assertSame('hidden', Mode::Hidden->value);
    }

    // =========================================================================
    // All three cases are distinct
    // =========================================================================

    public function testAllCasesAreDistinct(): void
    {
        $cases = Mode::cases();
        $this->assertCount(3, $cases);

        $values = array_map(static fn(Mode $c): string => $c->value, $cases);
        $this->assertCount(3, array_unique($values));
    }

    // =========================================================================
    // Backed enum — valueOf / tryFrom work
    // =========================================================================

    public function testValueOfReturnsCorrectCase(): void
    {
        $this->assertSame(Mode::Blink, Mode::from('blink'));
        $this->assertSame(Mode::Static, Mode::from('static'));
        $this->assertSame(Mode::Hidden, Mode::from('hidden'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(Mode::tryFrom('blink-fast'));
        $this->assertNull(Mode::tryFrom(''));
        $this->assertNull(Mode::tryFrom('HIDDEN')); // case-sensitive
    }

    // =========================================================================
    // match exhaustiveness — ensure all cases handled
    // =========================================================================

    public function testMatchCoversAllCases(): void
    {
        $cases = Mode::cases();
        $results = [];
        foreach ($cases as $case) {
            $results[$case->value] = match (true) {
                $case === Mode::Blink => 'blink-action',
                $case === Mode::Static => 'static-action',
                $case === Mode::Hidden => 'hidden-action',
            };
        }

        $this->assertCount(3, $results);
        $this->assertSame('blink-action', $results['blink']);
        $this->assertSame('static-action', $results['static']);
        $this->assertSame('hidden-action', $results['hidden']);
    }
}
