<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\BranchPattern;

/**
 * D-143: the wire vocabulary for BranchStyle. Values are (de)serialised
 * by config surfaces, so the string forms are part of the contract.
 */
final class BranchPatternTest extends TestCase
{
    public function testRosterAndWireValues(): void
    {
        self::assertSame(
            ['Solid', 'Dashed', 'Dotted', 'Double'],
            array_map(static fn(BranchPattern $p): string => $p->name, BranchPattern::cases()),
        );
        self::assertSame(
            ['solid', 'dashed', 'dotted', 'double'],
            array_map(static fn(BranchPattern $p): string => $p->value, BranchPattern::cases()),
        );
    }

    public function testEveryCaseRoundTripsThroughItsBackedValue(): void
    {
        foreach (BranchPattern::cases() as $case) {
            self::assertSame($case, BranchPattern::from($case->value));
        }

        self::assertNull(BranchPattern::tryFrom('wavy'));
    }
}
