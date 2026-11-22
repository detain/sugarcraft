<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Overlay\Placement;
use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;
use SugarCraft\Overlay\VeilStack;

/**
 * One placement value, one placement path: every door (composite(),
 * centered(), a veil's stored placement, the stack passes) routes through
 * Veil::place() and lands on the same cells.
 */
final class PlacementTest extends TestCase
{
    private const BG = "..........\n..........\n..........\n..........";

    public function testDefaultsAreCenteredWithoutOffset(): void
    {
        $p = Placement::new();

        $this->assertSame(Position::CENTER, $p->vertical);
        $this->assertSame(Position::CENTER, $p->horizontal);
        $this->assertSame([0, 0], [$p->x, $p->y]);
        $this->assertEquals($p, Placement::centered());
    }

    public function testWithersAreImmutable(): void
    {
        $p = Placement::new(Position::TOP, Position::LEFT, 1, 2);

        $anchors = $p->withAnchors(Position::BOTTOM, Position::RIGHT);
        $offset  = $p->withOffset(5, 6);
        $moved   = $p->offsetBy(1, -1);

        $this->assertSame([Position::TOP, Position::LEFT, 1, 2], [$p->vertical, $p->horizontal, $p->x, $p->y]);
        $this->assertSame([Position::BOTTOM, Position::RIGHT, 1, 2], [$anchors->vertical, $anchors->horizontal, $anchors->x, $anchors->y]);
        $this->assertSame([5, 6], [$offset->x, $offset->y]);
        $this->assertSame([2, 1], [$moved->x, $moved->y]);
        $this->assertSame($p, $p->offsetBy(0, 0));
    }

    public function testPlaceMatchesCompositeForEveryAnchorPair(): void
    {
        foreach (Position::cases() as $v) {
            foreach (Position::cases() as $h) {
                $this->assertSame(
                    Veil::new()->withBackdrop(40)->composite('AB', self::BG, $v, $h, 1, -1),
                    Veil::new()->withBackdrop(40)->place('AB', self::BG, Placement::new($v, $h, 1, -1)),
                    "{$v->name}/{$h->name}",
                );
            }
        }
    }

    public function testCenteredIsPlaceWithTheCenteredPlacement(): void
    {
        $this->assertSame(
            Veil::new()->withBackdrop(50)->place('XY', self::BG, Placement::centered(2, 1)),
            Veil::centered('XY', self::BG, 50, 2, 1),
        );
    }

    public function testPlaceWithoutAPlacementUsesTheVeilsOwn(): void
    {
        $veil = Veil::new()->withPlacement(Placement::new(Position::TOP, Position::RIGHT, -1, 1));

        $this->assertSame("..........\n.......AB.\n..........\n..........", $veil->place('AB', self::BG));
    }

    public function testWithPlacementAndWithPositionShareOneStorage(): void
    {
        $a = Veil::new()->withPlacement(Placement::new(Position::BOTTOM, Position::LEFT, 3, -2));
        $b = Veil::new()->withPosition(Position::BOTTOM, Position::LEFT, 3, -2);

        $this->assertEquals($a->placement(), $b->placement());
        $this->assertSame(Position::BOTTOM, $a->vPosition());
        $this->assertSame(3, $a->positionX());
    }

    public function testAnUnsetAxisReadsAsCenter(): void
    {
        $p = Veil::new()->withPosition(null, Position::LEFT, 1, 0)->placement();

        $this->assertSame(Position::CENTER, $p->vertical);
        $this->assertSame(Position::LEFT, $p->horizontal);
        $this->assertNull(Veil::new()->vPosition(), 'the stored anchor stays null');
    }

    public function testCompositeAllPlacesEachVeilAtItsPlacementPlusTheStackNudge(): void
    {
        $layer = Veil::new()->withContent('AB')->withPlacement(Placement::new(Position::TOP, Position::LEFT, 1, 0));

        $this->assertSame(
            $layer->withoutSession()->place('AB', self::BG, Placement::new(Position::TOP, Position::LEFT, 3, 1)),
            VeilStack::new()->add($layer)->compositeAll(self::BG, 2, 1),
        );
    }
}
