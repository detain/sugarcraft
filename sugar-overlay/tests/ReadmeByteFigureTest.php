<?php

declare(strict_types=1);

/*
 * m1 (lib-fixes-1103): the README's byte-difference claim used to quote
 * "~1,940 bytes" — a figure no fixture reproduced. This test extracts
 * the Quick Start fence's own $background/$modal assignments AND the
 * three byte figures from the prose, re-derives the numbers live, and
 * requires them to agree. The README can no longer drift from the code.
 */

namespace SugarCraft\Overlay\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Overlay\Position;
use SugarCraft\Overlay\Veil;

final class ReadmeByteFigureTest extends TestCase
{
    public function testQuickStartFixtureAndProseAgree(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $background = self::extractAssignment($readme, 'background');
        $modal = self::extractAssignment($readme, 'modal');

        $veil = Veil::new();
        $full = $veil->composite($modal, $background, Position::CENTER, Position::CENTER);

        // One-character change, same edit the prose describes.
        $changed = $modal;
        $at = strpos($changed, '?');
        self::assertNotFalse($at, 'the Quick Start modal carries a ? to flip');
        $changed[$at] = '!';
        $delta = $veil->composite($changed, $background, Position::CENTER, Position::CENTER);

        $dimmed = Veil::new()->withBackdrop(50)
            ->composite($modal, $background, Position::CENTER, Position::CENTER);

        // The prose must quote exactly these numbers.
        self::assertMatchesRegularExpression(
            sprintf('/is\s*%d bytes undimmed \(%d once a 50%% scrim[^)]*\)\s*,?\s*\n?while a\s*\n?one-character change diffs down to (%d) bytes/', strlen($full), strlen($dimmed), strlen($delta)),
            $readme,
            sprintf('README byte prose drifted: live full=%d dimmed=%d delta=%d', strlen($full), strlen($dimmed), strlen($delta))
        );
        $this->assertGreaterThan(0, strlen($delta));
    }

    private static function extractAssignment(string $readme, string $variable): string
    {
        self::assertMatchesRegularExpression('/\$' . preg_quote($variable, '/') . '\s*=\s*"((?:[^"\\\\]|\\\\.)*)";/', $readme);
        preg_match('/\$' . preg_quote($variable, '/') . '\s*=\s*"((?:[^"\\\\]|\\\\.)*)";/', $readme, $m);

        return stripcslashes($m[1]);
    }
}
