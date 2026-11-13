<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Heatmap\Heatmap;
use SugarCraft\Charts\Heatmap\HeatPoint;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * CH-13 / sugar-charts-021: `withColorProfile()` had zero test mentions —
 * the ctor seeds TrueColor and no pin swapped the color space, so a copy()
 * that failed to carry the profile was invisible to CI while sibling
 * setters were tested.
 */
final class HeatmapProfileTest extends TestCase
{
    private static function chart(): Heatmap
    {
        return Heatmap::new()
            ->pushAll([new HeatPoint(0, 0, 1.0), new HeatPoint(1, 0, 0.3)])
            ->withSize(6, 2)
            ->withColors(Color::rgb(0, 0, 80), Color::rgb(255, 60, 0));
    }

    public function testNonTrueColorProfileChangesEmittedColorBytes(): void
    {
        $trueColor = self::chart()->view();
        $ansi256   = self::chart()->withColorProfile(ColorProfile::Ansi256)->view();
        self::assertStringContainsString('38;2;', $trueColor, 'TrueColor baseline drifted');
        self::assertStringContainsString('38;5;', $ansi256, 'Ansi256 pin emits no indexed SGR');
        self::assertStringNotContainsString('38;2;', $ansi256, 'profile swap did not reach the cells');
        self::assertNotSame($trueColor, $ansi256);
    }

    public function testProfileSurvivesLaterCopies(): void
    {
        // A copy() that dropped the profile would resurface TrueColor
        // bytes here — the exact invisibility 021 describes.
        $out = self::chart()
            ->withColorProfile(ColorProfile::Ansi)
            ->withSize(6, 3)
            ->withRune('▓')
            ->view();
        self::assertStringNotContainsString('38;2;', $out);
    }

    public function testShortAliasCarriesTheSameProfile(): void
    {
        self::assertSame(
            self::chart()->withColorProfile(ColorProfile::Ansi256)->view(),
            self::chart()->colorProfile(ColorProfile::Ansi256)->view(),
        );
    }
}
