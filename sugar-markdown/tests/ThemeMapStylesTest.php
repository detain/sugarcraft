<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Markdown\Theme;
use SugarCraft\Style\Style;

/**
 * Pins for {@see Theme::mapStyles()} — the wholesale slot rewrite an
 * embedding terminal's contrast law uses (reviews-1103 A11Y-1). The
 * contract being protected here is structural: every painted Style is
 * offered to the mapper exactly once, nothing else is touched, and an
 * identity mapper rebuilds nothing.
 */
final class ThemeMapStylesTest extends TestCase
{
    public function testIdentityMapperPreservesValueAndSlotIdentity(): void
    {
        $theme = Theme::dracula();
        $mapped = $theme->mapStyles(static fn (Style $style): Style => $style);

        self::assertEquals($theme, $mapped, 'an identity mapper must rebuild an equal theme');
        self::assertNotSame($theme, $mapped, 'mapStyles() derives: a fresh instance every call');
        self::assertSame($theme->paragraph, $mapped->paragraph, 'a returned-as-given Style is stored as-given, not cloned');
        self::assertSame($theme->code, $mapped->code);
    }

    public function testEveryStyleSlotIsOfferedExactlyOnce(): void
    {
        $theme = Theme::dark();
        $seen = [];
        $mapped = $theme->mapStyles(static function (Style $style) use (&$seen): Style {
            $seen[] = spl_object_id($style);

            return $style;
        });

        $expected = [];
        foreach (get_object_vars($theme) as $value) {
            if ($value instanceof Style) {
                $expected[] = spl_object_id($value);
            }
        }

        self::assertNotEmpty($expected, 'the fixture must carry painted slots');
        self::assertSame($expected, $seen, 'each Style slot, in declaration order, exactly once');
        self::assertEquals($theme, $mapped);
    }

    public function testMapperRewritesForegroundsAndScalarsRideThrough(): void
    {
        $theme = Theme::dracula();
        $ink = Color::hex('#101010');
        $mapped = $theme->mapStyles(
            static fn (Style $style): Style => $style->getForeground() === null
                ? $style
                : $style->foreground($ink)
        );

        $painted = 0;
        foreach (get_object_vars($mapped) as $name => $value) {
            if (!$value instanceof Style) {
                continue;
            }
            $original = $theme->{$name};
            if ($original->getForeground() === null) {
                self::assertSame($original, $value, "{$name} had no foreground and must pass through untouched");
                continue;
            }
            ++$painted;
            self::assertSame($ink, $value->getForeground(), "{$name} foreground must be the mapper's colour");
            self::assertSame($original->getBackground(), $value->getBackground(), "{$name} background belongs to the mapper input, not this test");
        }

        self::assertGreaterThanOrEqual(10, $painted, 'dracula paints a broad palette; a scan that finds almost nothing is broken');
        self::assertSame($theme->documentMargin, $mapped->documentMargin, 'scalar layout fields ride through');
    }

    public function testUnsetOptionalSlotsStayUnset(): void
    {
        $theme = Theme::plain();
        $mapped = $theme->mapStyles(static fn (Style $style): Style => $style);

        self::assertNull($theme->orderedListMarker, 'the fixture slot is unset in plain()');
        self::assertNull($mapped->orderedListMarker, 'a null optional slot is never offered to the mapper and never invented');
        self::assertEquals($theme, $mapped);
    }

    public function testAMapperThatReturnsGarbageFailsLoud(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Theme::mapStyles() expects the mapper to return a Style');

        Theme::light()->mapStyles(static fn (Style $style) => 'not a style');
    }
}
