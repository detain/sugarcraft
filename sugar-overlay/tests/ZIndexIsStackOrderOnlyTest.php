<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use SugarCraft\Overlay\{Position, Veil, VeilStack};
use PHPUnit\Framework\TestCase;

/**
 * M4 (docs-fix-1023): zIndex is a STACK ORDER value. composite() never
 * reads it — the old README example implied a lone composite honored
 * z-index. Only VeilStack sorts, ascending, painting bottom-to-top.
 */
final class ZIndexIsStackOrderOnlyTest extends TestCase
{
    private const BG = "..............................\n..............................\n..............................";

    public function testCompositeOutputIsByteIdenticalRegardlessOfZIndex(): void
    {
        $low  = Veil::new()->withZIndex(0)->composite("[box]", self::BG, Position::CENTER, Position::CENTER);
        $high = Veil::new()->withZIndex(99)->composite("[box]", self::BG, Position::CENTER, Position::CENTER);

        $this->assertSame($low, $high, 'a single composite has nothing to stack against — zIndex is inert here');
    }

    public function testStackPaintsAscendingSoHigherZIndexWinsTheOverlap(): void
    {
        $stack = VeilStack::new()
            ->add(Veil::new()->withContent('[AAAAAA]')->withZIndex(1))
            ->add(Veil::new()->withContent('[BBBBBB]')->withZIndex(2));

        $frame = $stack->composite(self::BG, Position::CENTER, Position::CENTER);

        // Same size, same anchor: the later (higher) layer fully covers the lower.
        $this->assertStringContainsString('[BBBBBB]', $frame);
        $this->assertStringNotContainsString('[AAAAAA]', $frame);
    }

    public function testSwappedZIndexSwapsThePaintOrderWinner(): void
    {
        $stack = VeilStack::new()
            ->add(Veil::new()->withContent('[AAAAAA]')->withZIndex(9))
            ->add(Veil::new()->withContent('[BBBBBB]')->withZIndex(1));

        $frame = $stack->composite(self::BG, Position::CENTER, Position::CENTER);

        $this->assertStringContainsString('[AAAAAA]', $frame);
        $this->assertStringNotContainsString('[BBBBBB]', $frame);
    }
}
