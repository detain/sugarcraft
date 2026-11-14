<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Layout\Boxer\Boxer;
use SugarCraft\Dashboard\Layout\Boxer\Node;
use SugarCraft\Dashboard\Layout\FocusManager;
use SugarCraft\Dashboard\Layout\Grid\StackedGrid;
use SugarCraft\Dashboard\Layout\Support\PersistState;

/**
 * Pins for finding sugar-dashboard-121 (setSize stale geometry, degenerate
 * reset, memo) and for the sugar-dashboard-143 trait extraction.
 */
final class BoxerSizeStateTest extends TestCase
{
    /**
     * A Sizer that records every setSize() pair it is handed.
     */
    private function spy(): Sizer
    {
        return new class implements Sizer {
            /** @var list<array{int, int}> */
            public array $seen = [];

            public function setSize(int $width, int $height): Sizer
            {
                $this->seen[] = [$width, $height];

                return $this;
            }

            public function render(): string
            {
                return 'spy';
            }
        };
    }

    public function testSetSizeWithSameDimensionsReturnsSameInstance(): void
    {
        $boxer = Boxer::leaf('0', $this->spy());
        $sized = $boxer->setSize(80, 24);

        $this->assertInstanceOf(Boxer::class, $sized);
        $this->assertSame($sized, $sized->setSize(80, 24));
    }

    public function testModelsReceiveFreshTreeGeometryNotLastPassGeometry(): void
    {
        $spyTop = $this->spy();
        $spyBottom = $this->spy();
        $boxer = Boxer::tree(
            Node::vertical(Node::leaf('0'), Node::leaf('1')),
            ['0' => $spyTop, '1' => $spyBottom],
        );

        $first = $boxer->setSize(80, 24);
        $this->assertInstanceOf(Boxer::class, $first);
        foreach ([['0', $spyTop], ['1', $spyBottom]] as [$addr, $spy]) {
            $node = $first->node($addr);
            $this->assertNotNull($node);
            $this->assertSame($node->width(), $spy->seen[0][0], "address {$addr} first pass width");
            $this->assertSame($node->height(), $spy->seen[0][1], "address {$addr} first pass height");
        }

        $second = $first->setSize(40, 10);
        $this->assertInstanceOf(Boxer::class, $second);
        foreach ([['0', $spyTop], ['1', $spyBottom]] as [$addr, $spy]) {
            $node = $second->node($addr);
            $this->assertNotNull($node);
            $last = $spy->seen[count($spy->seen) - 1] ?? null;
            $this->assertNotNull($last, "address {$addr} was re-sized on the second pass");
            // Pre-cure this fed the geometry of the PREVIOUS tree (stale by
            // one pass); the returned tree's node sizes are the truth now.
            $this->assertSame($node->width(), $last[0], "address {$addr} second pass width");
            $this->assertSame($node->height(), $last[1], "address {$addr} second pass height");
        }
    }

    public function testDegenerateSizeResetsTreeGeometryInsteadOfKeepingStaleSizes(): void
    {
        $boxer = Boxer::tree(
            Node::vertical(Node::leaf('0'), Node::leaf('1')),
            ['0' => $this->spy(), '1' => $this->spy()],
        );
        $sized = $boxer->setSize(80, 24);
        $this->assertInstanceOf(Boxer::class, $sized);

        $collapsed = $sized->setSize(0, 5);
        $this->assertInstanceOf(Boxer::class, $collapsed);
        $this->assertSame(0, $collapsed->node('0')?->width());
        $this->assertSame(0, $collapsed->node('1')?->height());
        $this->assertSame('waiting for size information', $collapsed->render());
    }

    public function testLayoutEnginesShareOnePersistenceTrait(): void
    {
        // Reflection reports trait methods as declared on the USING class,
        // so the structural pin goes through getTraitNames() instead.
        foreach ([Boxer::class, StackedGrid::class] as $class) {
            $this->assertContains(
                PersistState::class,
                (new \ReflectionClass($class))->getTraitNames(),
                "{$class} must ride the shared PersistState trait",
            );
        }
    }

    public function testFocusManagerKeepsItsOwnDistinctPersistenceContract(): void
    {
        // Disclosed adjudication for finding sugar-dashboard-143: FocusManager
        // persists a focused id plus a focus map (D-08 fail-soft repair), not
        // the collapsed-addresses pair, so it deliberately does NOT ride the
        // shared trait. This pin keeps the split honest in both directions.
        $this->assertSame([], (new \ReflectionClass(FocusManager::class))->getTraitNames());
    }
}
