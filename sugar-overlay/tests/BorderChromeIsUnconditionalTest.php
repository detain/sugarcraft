<?php

declare(strict_types=1);

namespace SugarCraft\Overlay\Tests;

use SugarCraft\Style\Border;
use SugarCraft\Overlay\{Position, Veil};
use PHPUnit\Framework\TestCase;

/**
 * M3 (docs-fix-1023): border chrome is applied by composite() whenever a
 * border is set — the old autoSize gate made withBorder() silently inert
 * unless the caller also discovered withAutoSize(), contradicting the
 * README's unconditional promise. Pins both paths (flag set, flag unset)
 * and the no-double-wrap law.
 */
final class BorderChromeIsUnconditionalTest extends TestCase
{
    private const BG = "..............................\n..............................\n..............................";

    public function testBorderRendersWithoutTheAutoSizeFlag(): void
    {
        $frame = Veil::new()
            ->withBorder(Border::normal())
            ->composite("Hi", self::BG, Position::TOP, Position::LEFT);

        $firstLine = explode("\n", $frame)[0];
        $this->assertStringStartsWith('┌', $firstLine);
        $this->assertStringContainsString('Hi', $frame);
    }

    public function testBorderRendersIdenticallyWithTheDeprecatedFlag(): void
    {
        $off = Veil::new()
            ->withAutoSize(false)
            ->withBorder(Border::normal())
            ->composite("Hi", self::BG, Position::TOP, Position::LEFT);
        $on = Veil::new()
            ->withAutoSize(true)
            ->withBorder(Border::normal())
            ->composite("Hi", self::BG, Position::TOP, Position::LEFT);

        $this->assertSame($off, $on, 'autoSize is a documented no-op; a set border wraps exactly once either way');
    }

    public function testBorderAppliedExactlyOncePerComposite(): void
    {
        $frame = Veil::new()
            ->withBorder(Border::rounded())
            ->composite("Hi", self::BG, Position::TOP, Position::LEFT);

        // One rounded block: a single top-left corner glyph.
        $this->assertSame(1, substr_count($frame, '╭'));
    }

    public function testBorderlessCompositeCarriesNoChrome(): void
    {
        $frame = Veil::new()->composite("Hi", self::BG, Position::TOP, Position::LEFT);

        $this->assertStringStartsWith('Hi', $frame);
        $this->assertStringNotContainsString('┌', $frame);
    }

    public function testBorderWrapsTheEmptyBackgroundOverlayPath(): void
    {
        // bg=='' returns the overlay itself as the frame — with chrome.
        $veil  = Veil::new()->withBorder(Border::normal());
        $frame = $veil->composite('X', '', Position::CENTER, Position::CENTER);

        $this->assertStringStartsWith('┌', $frame);
    }
}
