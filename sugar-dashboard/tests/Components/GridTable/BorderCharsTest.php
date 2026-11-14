<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\GridTable\BorderChars;

/**
 * D-143: the glyph tables were only ever exercised through GridTable
 * rendering; the character-set contract itself (which corner each style
 * paints, and which slots every factory deliberately leaves at the ctor
 * default) had no direct pin. A wrong constant here re-skins every
 * bordered table while the suite stays green.
 */
final class BorderCharsTest extends TestCase
{
    public function testDefaultSetMatchesTheDocumentedConstants(): void
    {
        $b = BorderChars::new();

        self::assertSame(BorderChars::TOP_LEFT_DEFAULT, $b->topLeft);
        self::assertSame(BorderChars::TOP_DEFAULT, $b->top);
        self::assertSame(BorderChars::TOP_RIGHT_DEFAULT, $b->topRight);
        self::assertSame(BorderChars::LEFT_DEFAULT, $b->left);
        self::assertSame(BorderChars::BOTTOM_LEFT_DEFAULT, $b->bottomLeft);
        self::assertSame(BorderChars::BOTTOM_DEFAULT, $b->bottom);
        self::assertSame(BorderChars::BOTTOM_RIGHT_DEFAULT, $b->bottomRight);
        self::assertSame(BorderChars::RIGHT_DEFAULT, $b->right);
        self::assertSame(BorderChars::TOP_MID_DEFAULT, $b->topMid);
        self::assertSame(BorderChars::BOTTOM_MID_DEFAULT, $b->bottomMid);
        self::assertSame(BorderChars::CENTER_DEFAULT, $b->center);
        self::assertSame(BorderChars::LEFT_JUNCTION_DEFAULT, $b->leftJunction);
        self::assertSame(BorderChars::RIGHT_JUNCTION_DEFAULT, $b->rightJunction);
        self::assertSame(BorderChars::FREEZE_DIVIDER_DEFAULT, $b->freezeDivider);
        self::assertSame(BorderChars::FREEZE_TOP_JUNCTION_DEFAULT, $b->freezeTopJunction);
        self::assertSame(BorderChars::FREEZE_BOTTOM_JUNCTION_DEFAULT, $b->freezeBottomJunction);
        self::assertSame('┏', $b->topLeft);
    }

    public function testRoundedSetMatchesTheDocumentedConstants(): void
    {
        $b = BorderChars::rounded();

        self::assertSame(BorderChars::TOP_LEFT_ROUNDED, $b->topLeft);
        self::assertSame(BorderChars::TOP_ROUNDED, $b->top);
        self::assertSame(BorderChars::TOP_RIGHT_ROUNDED, $b->topRight);
        self::assertSame(BorderChars::LEFT_ROUNDED, $b->left);
        self::assertSame(BorderChars::BOTTOM_LEFT_ROUNDED, $b->bottomLeft);
        self::assertSame(BorderChars::BOTTOM_RIGHT_ROUNDED, $b->bottomRight);
        self::assertSame(BorderChars::RIGHT_ROUNDED, $b->right);
        self::assertSame(BorderChars::TOP_MID_ROUNDED, $b->topMid);
        self::assertSame(BorderChars::BOTTOM_MID_ROUNDED, $b->bottomMid);
        self::assertSame(BorderChars::CENTER_ROUNDED, $b->center);
        self::assertSame(BorderChars::LEFT_JUNCTION_ROUNDED, $b->leftJunction);
        self::assertSame(BorderChars::RIGHT_JUNCTION_ROUNDED, $b->rightJunction);
        self::assertSame('╭', $b->topLeft);
        self::assertSame('┼', $b->center);
    }

    public function testBorderlessSetIsEverySlotEmptyString(): void
    {
        $b = BorderChars::borderless();

        $slots = [
            $b->topLeft, $b->top, $b->topRight, $b->left, $b->bottomLeft,
            $b->bottom, $b->bottomRight, $b->right, $b->topMid, $b->bottomMid,
            $b->center, $b->leftJunction, $b->rightJunction, $b->freezeDivider,
            $b->freezeTopJunction, $b->freezeBottomJunction,
        ];

        foreach ($slots as $index => $glyph) {
            self::assertSame('', $glyph, "borderless slot {$index} must be empty");
        }
    }

    public function testMinimalSetUsesThinRulersWithSpaceCorners(): void
    {
        $b = BorderChars::minimal();

        self::assertSame(' ', $b->topLeft);
        self::assertSame('─', $b->top);
        self::assertSame(' ', $b->topRight);
        self::assertSame('│', $b->left);
        self::assertSame(' ', $b->bottomLeft);
        self::assertSame('─', $b->bottom);
        self::assertSame(' ', $b->bottomRight);
        self::assertSame('│', $b->right);
        self::assertSame(' ', $b->topMid);
        self::assertSame(' ', $b->bottomMid);
        self::assertSame(' ', $b->center);
        self::assertSame(' ', $b->leftJunction);
        self::assertSame(' ', $b->rightJunction);
        self::assertSame('│', $b->freezeDivider);
    }

    public function testNoFactoryTouchesTheCtorDefaultOnlySlots(): void
    {
        // topJunction/bottomJunction/innerJunction/innerDivider and
        // freezeInnerJunction are never passed by any of the four
        // factories, so all four must carry the declared defaults.
        foreach ([BorderChars::new(), BorderChars::rounded(), BorderChars::borderless(), BorderChars::minimal()] as $set) {
            self::assertSame('┳', $set->topJunction);
            self::assertSame('┻', $set->bottomJunction);
            self::assertSame('╋', $set->innerJunction);
            self::assertSame('┃', $set->innerDivider);
            self::assertSame('╫', $set->freezeInnerJunction);
        }
    }

    public function testEachFactoryMemoizesToOneIdentity(): void
    {
        // The static $instance per factory is the object-equality
        // contract consumers rely on when comparing configured sets.
        self::assertSame(BorderChars::new(), BorderChars::new());
        self::assertSame(BorderChars::rounded(), BorderChars::rounded());
        self::assertSame(BorderChars::borderless(), BorderChars::borderless());
        self::assertSame(BorderChars::minimal(), BorderChars::minimal());
        self::assertNotSame(BorderChars::new(), BorderChars::rounded());
    }
}
