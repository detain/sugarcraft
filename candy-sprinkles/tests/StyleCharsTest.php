<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles\Tests;

use CandyCore\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the new {@see Style::paddingChar()} / {@see Style::marginChar()}
 * setters. Both default to `' '` so existing snapshots stay unchanged
 * unless the caller asks for a different fill glyph.
 */
final class StyleCharsTest extends TestCase
{
    public function testPaddingCharDefaultIsSpace(): void
    {
        $this->assertSame(' ', Style::new()->getPaddingChar());
    }

    public function testMarginCharDefaultIsSpace(): void
    {
        $this->assertSame(' ', Style::new()->getMarginChar());
    }

    public function testCustomPaddingCharFillsCells(): void
    {
        $rendered = Style::new()
            ->padding(0, 2)
            ->paddingChar('·')
            ->render('hi');
        $this->assertStringContainsString('··hi··', $rendered);
    }

    public function testCustomMarginCharFillsCells(): void
    {
        $rendered = Style::new()
            ->margin(0, 2)
            ->marginChar('-')
            ->render('hi');
        // The result includes the margin glyph on each side of the content row.
        $lines = explode("\n", $rendered);
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertStringContainsString('--', $line);
        }
    }

    public function testEmptyCharFallsBackToSpace(): void
    {
        // Empty / zero-width fallback so callers can't break the layout
        // by passing ''.
        $rendered = Style::new()
            ->padding(0, 1)
            ->paddingChar('')
            ->render('x');
        // Length should stay consistent (1 cell padded each side + content).
        $this->assertSame(' x ', $rendered);
    }
}
