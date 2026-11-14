<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\ClassMember;
use SugarCraft\Dashboard\Components\Tree\Visibility;

/**
 * D-143: the UML marker vocabulary. ClassMember renders Public/Private/
 * Protected through the factories, but Package ('~', the directory-visible
 * arm) is reachable only via the constructor — the roster pin covers all
 * four wire values so a re-spelling (e.g. the tilde drifting to '~') is
 * caught at the vocabulary level, not deep in diagram goldens.
 */
final class VisibilityTest extends TestCase
{
    public function testRosterAndWireValues(): void
    {
        self::assertSame(
            ['Public', 'Private', 'Protected', 'Package'],
            array_map(static fn(Visibility $v): string => $v->name, Visibility::cases()),
        );
        self::assertSame(
            ['+', '-', '#', '~'],
            array_map(static fn(Visibility $v): string => $v->value, Visibility::cases()),
        );
    }

    public function testPackageArmPaintsThroughClassMember(): void
    {
        $member = new ClassMember(Visibility::Package, 'helper', 'void');

        self::assertSame('~helper: void', $member->render());
    }

    public function testEveryCaseRoundTripsThroughItsBackedValue(): void
    {
        foreach (Visibility::cases() as $case) {
            self::assertSame($case, Visibility::from($case->value));
        }

        self::assertNull(Visibility::tryFrom('!'));
    }
}
