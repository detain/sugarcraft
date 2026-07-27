<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Vim;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Vim\VimState;

/**
 * Tests for {@see VimState} — vim editing states handled by {@see VimKeyHandler}.
 */
final class VimStateTest extends TestCase
{
    // =========================================================================
    // Enum cases exist and have expected string values
    // =========================================================================

    public function testInsertCaseExists(): void
    {
        $this->assertSame('insert', VimState::Insert->value);
    }

    public function testNormalCaseExists(): void
    {
        $this->assertSame('normal', VimState::Normal->value);
    }

    public function testVisualCaseExists(): void
    {
        $this->assertSame('visual', VimState::Visual->value);
    }

    public function testVisualLineCaseExists(): void
    {
        $this->assertSame('visual-line', VimState::VisualLine->value);
    }

    // =========================================================================
    // All four cases are distinct
    // =========================================================================

    public function testAllCasesAreDistinct(): void
    {
        $cases = VimState::cases();
        $this->assertCount(4, $cases);

        $values = array_map(static fn(VimState $c): string => $c->value, $cases);
        $this->assertCount(4, array_unique($values));
    }

    // =========================================================================
    // Backed enum — valueOf / tryFrom work
    // =========================================================================

    public function testValueOfReturnsCorrectCase(): void
    {
        $this->assertSame(VimState::Insert, VimState::from('insert'));
        $this->assertSame(VimState::Normal, VimState::from('normal'));
        $this->assertSame(VimState::Visual, VimState::from('visual'));
        $this->assertSame(VimState::VisualLine, VimState::from('visual-line'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(VimState::tryFrom('i'));
        $this->assertNull(VimState::tryFrom('command'));
        $this->assertNull(VimState::tryFrom(''));
        $this->assertNull(VimState::tryFrom('INSERT')); // case-sensitive
    }

    // =========================================================================
    // match exhaustiveness — ensure all cases handled
    // =========================================================================

    public function testMatchCoversAllCases(): void
    {
        $cases = VimState::cases();
        $results = [];
        foreach ($cases as $case) {
            $results[$case->value] = match (true) {
                $case === VimState::Insert => 'insert-mode',
                $case === VimState::Normal => 'normal-mode',
                $case === VimState::Visual => 'visual-mode',
                $case === VimState::VisualLine => 'visual-line-mode',
            };
        }

        $this->assertCount(4, $results);
        $this->assertSame('insert-mode', $results['insert']);
        $this->assertSame('normal-mode', $results['normal']);
        $this->assertSame('visual-mode', $results['visual']);
        $this->assertSame('visual-line-mode', $results['visual-line']);
    }
}
