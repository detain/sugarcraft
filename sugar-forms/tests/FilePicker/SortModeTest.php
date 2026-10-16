<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\FilePicker;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\FilePicker\SortMode;

/**
 * Tests for {@see SortMode} — sort criterion for {@see FilePicker::withSortMode()}.
 */
final class SortModeTest extends TestCase
{
    // =========================================================================
    // Enum cases exist and have expected string values
    // =========================================================================

    public function testNameCaseExists(): void
    {
        $this->assertSame('name', SortMode::Name->value);
    }

    public function testSizeCaseExists(): void
    {
        $this->assertSame('size', SortMode::Size->value);
    }

    public function testMTimeCaseExists(): void
    {
        $this->assertSame('mtime', SortMode::MTime->value);
    }

    // =========================================================================
    // All three cases are distinct
    // =========================================================================

    public function testAllCasesAreDistinct(): void
    {
        $cases = SortMode::cases();
        $this->assertCount(3, $cases);

        $values = array_map(static fn(SortMode $c): string => $c->value, $cases);
        $this->assertCount(3, array_unique($values));
    }

    // =========================================================================
    // Backed enum — valueOf / tryFrom work
    // =========================================================================

    public function testValueOfReturnsCorrectCase(): void
    {
        $this->assertSame(SortMode::Name, SortMode::from('name'));
        $this->assertSame(SortMode::Size, SortMode::from('size'));
        $this->assertSame(SortMode::MTime, SortMode::from('mtime'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        $this->assertNull(SortMode::tryFrom('modified'));
        $this->assertNull(SortMode::tryFrom('date'));
        $this->assertNull(SortMode::tryFrom(''));
        $this->assertNull(SortMode::tryFrom('NAME')); // case-sensitive
    }

    // =========================================================================
    // match exhaustiveness — ensure all cases handled
    // =========================================================================

    public function testMatchCoversAllCases(): void
    {
        $cases = SortMode::cases();
        $results = [];
        foreach ($cases as $case) {
            $results[$case->value] = match (true) {
                $case === SortMode::Name => 'sort-by-name',
                $case === SortMode::Size => 'sort-by-size',
                $case === SortMode::MTime => 'sort-by-mtime',
            };
        }

        $this->assertCount(3, $results);
        $this->assertSame('sort-by-name', $results['name']);
        $this->assertSame('sort-by-size', $results['size']);
        $this->assertSame('sort-by-mtime', $results['mtime']);
    }
}
