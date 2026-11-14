<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Components\Tree\BranchPattern;
use SugarCraft\Dashboard\Components\Tree\BranchStyle;

/**
 * D-143: BranchStyle is the glyph source for branch painting and carried
 * zero direct pins. Two contracts live here that a refactor would
 * silently flip: (1) double() is the only factory that raises width to
 * 2, (2) dashed and dotted deliberately SHARE their connector glyphs
 * ('╟'/'╙') while their line glyphs differ — the shared arms are the
 * design, not an oversight.
 */
final class BranchStyleTest extends TestCase
{
    public function testFactoriesSetPatternAndWidthWithTheDoubleException(): void
    {
        $solid = BranchStyle::solid();
        self::assertSame(BranchPattern::Solid, $solid->pattern);
        self::assertSame(1, $solid->width);

        $dashed = BranchStyle::dashed();
        self::assertSame(BranchPattern::Dashed, $dashed->pattern);
        self::assertSame(1, $dashed->width);

        $dotted = BranchStyle::dotted();
        self::assertSame(BranchPattern::Dotted, $dotted->pattern);
        self::assertSame(1, $dotted->width);

        // Double-line renders as two cells wide — the only factory where
        // width is not 1.
        $double = BranchStyle::double();
        self::assertSame(BranchPattern::Double, $double->pattern);
        self::assertSame(2, $double->width);

        foreach ([$solid, $dashed, $dotted, $double] as $style) {
            self::assertTrue($style->showArrow);
            // The documented fallback is the Tokyo Night comment grey.
            self::assertNotNull($style->color);
            self::assertSame('#45475a', strtolower($style->color->toHex()));
        }
    }

    public function testExplicitColorTravelsAndNullIsRejectedByTheFactories(): void
    {
        $color = Color::hex('#89B4FA');

        self::assertSame($color, BranchStyle::solid($color)->color);
        // null inputs fall to the same fallback as omission.
        self::assertSame('#45475a', strtolower(BranchStyle::dashed(null)->color->toHex()));
    }

    public function testLineGlyphMatrix(): void
    {
        self::assertSame('─', BranchStyle::solid()->lineChar());
        self::assertSame('┅', BranchStyle::dashed()->lineChar());
        self::assertSame('┄', BranchStyle::dotted()->lineChar());
        self::assertSame('═', BranchStyle::double()->lineChar());
    }

    public function testConnectorGlyphMatrixWithDeliberateDashedDottedSharing(): void
    {
        self::assertSame('├', BranchStyle::solid()->connectorChar());
        self::assertSame('╠', BranchStyle::double()->connectorChar());
        // Dashed and dotted intentionally share the thin-box connector.
        self::assertSame('╟', BranchStyle::dashed()->connectorChar());
        self::assertSame('╟', BranchStyle::dotted()->connectorChar());

        self::assertSame('└', BranchStyle::solid()->endConnectorChar());
        self::assertSame('╚', BranchStyle::double()->endConnectorChar());
        self::assertSame('╙', BranchStyle::dashed()->endConnectorChar());
        self::assertSame('╙', BranchStyle::dotted()->endConnectorChar());
    }

    public function testWidthClampsToOneButNeverRoundsPatternsAway(): void
    {
        $style = BranchStyle::solid();

        self::assertSame(1, $style->withWidth(0)->width);
        self::assertSame(1, $style->withWidth(-5)->width);
        self::assertSame(3, $style->withWidth(3)->width);
        self::assertSame(1, $style->width, 'the original must not move');
    }

    public function testWithersCarryEveryOtherSlot(): void
    {
        $base = BranchStyle::double(Color::hex('#F38BA8'));

        $recolored = $base->withColor(null);
        self::assertNull($recolored->color);
        self::assertSame(BranchPattern::Double, $recolored->pattern);
        self::assertSame(2, $recolored->width);
        self::assertTrue($recolored->showArrow);

        $repatterned = $base->withPattern(BranchPattern::Solid);
        self::assertSame(BranchPattern::Solid, $repatterned->pattern);
        // width 2 survives a pattern swap — the width is the caller's,
        // not the pattern factory's, once set.
        self::assertSame(2, $repatterned->width);
        self::assertSame('─', $repatterned->lineChar());

        $noArrow = $base->withArrow(false);
        self::assertFalse($noArrow->showArrow);
        self::assertSame(BranchPattern::Double, $noArrow->pattern);
        self::assertTrue($base->showArrow);
    }
}
