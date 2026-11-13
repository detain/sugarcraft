<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Table;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Table\SortDirection;
use SugarCraft\Widgets\Table\SortState;

/**
 * Tests for {@see SortState} — immutable sort criteria container.
 */
final class SortStateTest extends TestCase
{
    public function testEmptyFactory(): void
    {
        $s = SortState::empty();
        $this->assertSame([], $s->criteria);
        $this->assertTrue($s->isEmpty());
    }

    public function testDefaultConstructorYieldsEmpty(): void
    {
        $s = new SortState();
        $this->assertSame([], $s->criteria);
        $this->assertTrue($s->isEmpty());
    }

    public function testWithCriterionAppendsToCriteria(): void
    {
        $s = SortState::empty()->withCriterion(0, SortDirection::Asc);
        $this->assertCount(1, $s->criteria);
        $this->assertSame([0, SortDirection::Asc], $s->criteria[0]);
    }

    public function testWithCriterionIsImmutable(): void
    {
        $s1 = SortState::empty();
        $s2 = $s1->withCriterion(0, SortDirection::Asc);
        $s3 = $s2->withCriterion(1, SortDirection::Desc);

        $this->assertSame([], $s1->criteria);
        $this->assertCount(1, $s2->criteria);
        $this->assertCount(2, $s3->criteria);
    }

    public function testWithCriterionChainsCorrectly(): void
    {
        $s = SortState::empty()
            ->withCriterion(0, SortDirection::Asc)
            ->withCriterion(1, SortDirection::Desc)
            ->withCriterion(2, SortDirection::Asc);

        $this->assertCount(3, $s->criteria);
        $this->assertSame([0, SortDirection::Asc], $s->criteria[0]);
        $this->assertSame([1, SortDirection::Desc], $s->criteria[1]);
        $this->assertSame([2, SortDirection::Asc], $s->criteria[2]);
    }

    public function testIsEmptyReturnsFalseWhenCriteriaPresent(): void
    {
        $s = SortState::empty()->withCriterion(0, SortDirection::Asc);
        $this->assertFalse($s->isEmpty());
    }

    public function testCriteriaPreservesOrder(): void
    {
        $s = SortState::empty()
            ->withCriterion(2, SortDirection::Desc)
            ->withCriterion(0, SortDirection::Asc);

        $this->assertSame(2, $s->criteria[0][0]);
        $this->assertSame(SortDirection::Desc, $s->criteria[0][1]);
        $this->assertSame(0, $s->criteria[1][0]);
        $this->assertSame(SortDirection::Asc, $s->criteria[1][1]);
    }

    public function testImmutabilityOfReturnedInstance(): void
    {
        $s1 = SortState::empty()->withCriterion(0, SortDirection::Asc);
        $s2 = $s1->withCriterion(1, SortDirection::Desc);

        // s1 unchanged
        $this->assertCount(1, $s1->criteria);
        // s2 has both
        $this->assertCount(2, $s2->criteria);
    }

    /**
     * Structural pin (PB-WIDGETS-3): the class is declared `final readonly`,
     * so the criteria vector is frozen at construction for every instance —
     * the only writer tree-wide is withCriterion()'s own `new self($criteria)`
     * local copy. Reflection is the contract: if the declaration is ever
     * relaxed the first assert reddens even while behaviour looks identical.
     */
    public function testTheClassIsDeclaredFinalReadonly(): void
    {
        $class = new \ReflectionClass(SortState::class);

        $this->assertTrue($class->isReadOnly(), 'SortState must stay `readonly`');
        $this->assertTrue($class->isFinal(), 'SortState must stay `final`');
        $this->assertTrue($class->getProperty('criteria')->isReadOnly());
    }

    /**
     * Behavioural pin (PB-WIDGETS-3): an external write to the public
     * $criteria vector raises an Error — mutation from outside the class
     * is impossible, not merely unidiomatic.
     */
    public function testExternalWriteToCriteriaRaisesError(): void
    {
        $s = SortState::empty();

        try {
            $s->criteria = [[0, SortDirection::Asc]];
            $this->fail('SortState is final readonly; external write must raise Error');
        } catch (\Error $e) {
            $this->assertStringContainsString('Cannot modify readonly property', $e->getMessage());
        }
    }

    public function testWithoutColumnsBeyondIdentityWhenAllInScope(): void
    {
        $s = new SortState([[0, SortDirection::Asc]]);
        $this->assertSame($s, $s->withoutColumnsBeyond(3), 'nothing dropped -> same instance');
    }

    public function testWithoutColumnsBeyondIdentityWhenAlreadyEmpty(): void
    {
        $s = new SortState();
        $this->assertSame($s, $s->withoutColumnsBeyond(0), 'empty stays empty, same instance');
    }

    public function testWithoutColumnsBeyondShrinksTheChain(): void
    {
        $s = (new SortState())->withCriterion(0, SortDirection::Asc)->withCriterion(2, SortDirection::Desc);
        $kept = $s->withoutColumnsBeyond(2);
        $this->assertNotSame($s, $kept, 'a drop is a new instance (immutable)');
        $this->assertSame([[0, SortDirection::Asc]], $kept->criteria, 'only the in-scope criterion survives');
    }
}
