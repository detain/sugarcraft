<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Layout\Constraint\Fill;
use SugarCraft\Layout\Constraint\Length;
use SugarCraft\Layout\Constraint\Max;
use SugarCraft\Layout\Constraint\Min;
use SugarCraft\Layout\Constraint\Percentage;
use SugarCraft\Layout\Constraint\Ratio;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\Region;

final class ConstraintTest extends TestCase
{
    // ── Constraint validation ──────────────────────────────────────────────

    public function testLengthRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Length(-1);
    }

    public function testMinRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Min(-1);
    }

    public function testMaxRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Max(-1);
    }

    public function testFillRejectsNegativeWeight(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Fill(-1);
    }

    public function testPercentageRejectsOutOfRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Percentage(150);
    }

    public function testPercentageRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Percentage(-1);
    }

    public function testRatioRejectsNegativeNumerator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ratio(-1, 3);
    }

    public function testRatioRejectsZeroDenominator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Ratio(1, 0);
    }

    // ── Factory methods ────────────────────────────────────────────────────

    public function testConstraintFactories(): void
    {
        $l = Constraint::length(10);
        $m = Constraint::min(5);
        $f = Constraint::fill(2);

        $this->assertInstanceOf(Length::class, $l);
        $this->assertInstanceOf(Min::class, $m);
        $this->assertInstanceOf(Fill::class, $f);

        $this->assertSame(10, $l->n);
        $this->assertSame(5, $m->n);
        $this->assertSame(2, $f->weight);
    }

    public function testFillDefaultWeight(): void
    {
        $this->assertSame(1, Constraint::fill()->weight);
    }

    public function testPercentageFactory(): void
    {
        $p = Constraint::percentage(30);
        $this->assertInstanceOf(Percentage::class, $p);
        $this->assertSame(30, $p->n);
    }

    public function testRatioFactory(): void
    {
        $r = Constraint::ratio(1, 3);
        $this->assertInstanceOf(Ratio::class, $r);
        $this->assertSame(1, $r->numerator);
        $this->assertSame(3, $r->denominator);
    }

    public function testMaxFactory(): void
    {
        $m = Constraint::max(50);
        $this->assertInstanceOf(Max::class, $m);
        $this->assertSame(50, $m->n);
    }

    // ── Region ────────────────────────────────────────────────────────────

    public function testRegionProperties(): void
    {
        $r = new Region(5, 10, 80, 24);
        $this->assertSame(5, $r->x);
        $this->assertSame(10, $r->y);
        $this->assertSame(80, $r->width);
        $this->assertSame(24, $r->height);
    }

    public function testRegionFromSize(): void
    {
        $r = Region::fromSize(80, 24);
        $this->assertSame(0, $r->x);
        $this->assertSame(0, $r->y);
        $this->assertSame(80, $r->width);
        $this->assertSame(24, $r->height);
    }

    // ── Direction enum ─────────────────────────────────────────────────────

    public function testDirectionCases(): void
    {
        $h = Direction::Horizontal;
        $v = Direction::Vertical;
        $this->assertSame('Horizontal', $h->name);
        $this->assertSame('Vertical', $v->name);
    }
}
