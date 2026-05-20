<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Lister\FilterState;
use PHPUnit\Framework\TestCase;

final class FilterStateTest extends TestCase
{
    public function testUnfiltered(): void
    {
        $state = FilterState::unfiltered;
        $this->assertSame('unfiltered', match ($state) {
            FilterState::unfiltered => 'unfiltered',
            FilterState::filtering => 'filtering',
            FilterState::filtered => 'filtered',
        });
    }

    public function testFiltering(): void
    {
        $state = FilterState::filtering;
        $this->assertSame('filtering', match ($state) {
            FilterState::unfiltered => 'unfiltered',
            FilterState::filtering => 'filtering',
            FilterState::filtered => 'filtered',
        });
    }

    public function testFiltered(): void
    {
        $state = FilterState::filtered;
        $this->assertSame('filtered', match ($state) {
            FilterState::unfiltered => 'unfiltered',
            FilterState::filtering => 'filtering',
            FilterState::filtered => 'filtered',
        });
    }

    public function testAllCases(): void
    {
        $cases = FilterState::cases();
        $this->assertCount(3, $cases);
        $this->assertContainsOnly(FilterState::class, $cases);
    }
}
