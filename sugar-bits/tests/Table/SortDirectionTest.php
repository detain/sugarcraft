<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Table\SortDirection;

/**
 * Tests for {@see SortDirection} enum.
 */
final class SortDirectionTest extends TestCase
{
    public function testAscCaseValue(): void
    {
        $this->assertSame('asc', SortDirection::Asc->value);
    }

    public function testDescCaseValue(): void
    {
        $this->assertSame('desc', SortDirection::Desc->value);
    }

    public function testToggleAscReturnsDesc(): void
    {
        $this->assertSame(SortDirection::Desc, SortDirection::Asc->toggle());
    }

    public function testToggleDescReturnsAsc(): void
    {
        $this->assertSame(SortDirection::Asc, SortDirection::Desc->toggle());
    }

    public function testToggleIsIdempotent(): void
    {
        $asc = SortDirection::Asc;
        $this->assertSame(SortDirection::Desc, $asc->toggle());
        $this->assertSame(SortDirection::Asc, $asc->toggle()->toggle());
    }

    public function testEnumCasesAreDistinct(): void
    {
        $this->assertNotSame(SortDirection::Asc, SortDirection::Desc);
    }

    public function testAscName(): void
    {
        $this->assertSame('Asc', SortDirection::Asc->name);
    }

    public function testDescName(): void
    {
        $this->assertSame('Desc', SortDirection::Desc->name);
    }
}
