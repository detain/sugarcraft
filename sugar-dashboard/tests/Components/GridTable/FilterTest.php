<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\GridTable\Filter;

/**
 * D-143 boundary matrix for the Filter carrier. FilterRowContractTest
 * (D-149) owns the activity-polarity cross-check against GridTable
 * admission; this file pins the remaining arms: construction, per-column
 * lookup (hit / miss / stored-empty), targeted removal, and clear.
 */
final class FilterTest extends TestCase
{
    public function testNoneAndFromArrayCoverBothEntryDoors(): void
    {
        self::assertSame([], Filter::none()->filters());
        self::assertFalse(Filter::none()->isActive());

        $filter = Filter::fromArray(['name' => 'abc', 'city' => 'NY']);
        self::assertSame(['name' => 'abc', 'city' => 'NY'], $filter->filters());
        self::assertTrue($filter->isActive());
    }

    public function testLookupReturnsNullOnlyForMissingColumns(): void
    {
        $filter = Filter::fromArray(['name' => 'abc', 'city' => '']);

        self::assertSame('abc', $filter->filter('name'));
        // A stored empty string is distinguishable from a missing column:
        // filter() reports '' (present) where missing yields null.
        self::assertSame('', $filter->filter('city'));
        self::assertNull($filter->filter('missing'));
    }

    public function testHasFilterPolarityAcrossAbsentEmptyAndFilled(): void
    {
        $filter = Filter::fromArray(['name' => 'abc', 'city' => '']);

        self::assertTrue($filter->hasFilter('name'));
        // '' is a neutral entry: present but never "active".
        self::assertFalse($filter->hasFilter('city'));
        self::assertFalse($filter->hasFilter('missing'));
    }

    public function testWithFilterAddsReplacesAndCarriesUntouchedColumns(): void
    {
        $base = Filter::fromArray(['name' => 'abc']);

        $added = $base->withFilter('city', 'NY');
        self::assertSame(['name' => 'abc', 'city' => 'NY'], $added->filters());
        // Immutability: the base filter never sees the addition.
        self::assertSame(['name' => 'abc'], $base->filters());

        $replaced = $base->withFilter('name', 'xyz');
        self::assertSame(['name' => 'xyz'], $replaced->filters());

        // A neutral '' entry travels in filters() but flips nothing active.
        $neutral = $base->withFilter('city', '');
        self::assertSame('', $neutral->filter('city'));
        self::assertTrue($neutral->isActive());
    }

    public function testWithoutFilterIsTargetedAndTolerantOfMissing(): void
    {
        $base = Filter::fromArray(['name' => 'abc', 'city' => 'NY']);

        $removed = $base->withoutFilter('name');
        self::assertSame(['city' => 'NY'], $removed->filters());
        self::assertSame(['name' => 'abc', 'city' => 'NY'], $base->filters());

        // Removing a column that was never set is a value-equal no-op.
        $noop = $base->withoutFilter('missing');
        self::assertSame(['name' => 'abc', 'city' => 'NY'], $noop->filters());
    }

    public function testClearWipesEveryEntryRegardlessOfSource(): void
    {
        $filter = Filter::none()
            ->withFilter('name', 'abc')
            ->withFilter('city', '')
            ->clear();

        self::assertSame([], $filter->filters());
        self::assertFalse($filter->isActive());
    }
}
