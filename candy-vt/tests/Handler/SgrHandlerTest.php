<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Handler\SgrHandler;
use SugarCraft\Vt\Sgr\Sgr;

final class SgrHandlerTest extends TestCase
{
    private function apply(array $params, ?Sgr $start = null): Sgr
    {
        return (new SgrHandler())->apply($params, $start ?? Sgr::empty());
    }

    public function testEmptyParamsResetsToEmpty(): void
    {
        $sgr = $this->apply([], Sgr::empty()->withBold(true));
        $this->assertFalse($sgr->bold);
    }

    public function testParam0ResetsAllFlags(): void
    {
        $start = Sgr::empty()
            ->withBold(true)
            ->withItalic(true)
            ->withForeground(Color::indexed16(1));
        $sgr = $this->apply([0], $start);
        $this->assertFalse($sgr->bold);
        $this->assertFalse($sgr->italic);
        $this->assertNull($sgr->foreground);
    }

    public function testDefaultParamTreatedAsZero(): void
    {
        $sgr = $this->apply([-1], Sgr::empty()->withBold(true));
        $this->assertFalse($sgr->bold);
    }

    public function testBoldOnAndOff(): void
    {
        $sgr = $this->apply([1]);
        $this->assertTrue($sgr->bold);
        $sgr = $this->apply([22], $sgr);
        $this->assertFalse($sgr->bold);
    }

    public function testDimAlsoClearedBy22(): void
    {
        $sgr = $this->apply([1, 2]);
        $this->assertTrue($sgr->bold);
        $this->assertTrue($sgr->dim);
        $sgr = $this->apply([22], $sgr);
        $this->assertFalse($sgr->bold);
        $this->assertFalse($sgr->dim);
    }

    public function testItalicToggle(): void
    {
        $sgr = $this->apply([3]);
        $this->assertTrue($sgr->italic);
        $sgr = $this->apply([23], $sgr);
        $this->assertFalse($sgr->italic);
    }

    public function testUnderlineToggle(): void
    {
        $sgr = $this->apply([4]);
        $this->assertTrue($sgr->underline);
        $sgr = $this->apply([24], $sgr);
        $this->assertFalse($sgr->underline);
    }

    public function testBlinkToggle(): void
    {
        $sgr = $this->apply([5]);
        $this->assertTrue($sgr->blink);
        $sgr = $this->apply([25], $sgr);
        $this->assertFalse($sgr->blink);
    }

    public function testRapidBlinkFoldsToBlink(): void
    {
        $sgr = $this->apply([6]);
        $this->assertTrue($sgr->blink);
    }

    public function testReverseToggle(): void
    {
        $sgr = $this->apply([7]);
        $this->assertTrue($sgr->reverse);
        $sgr = $this->apply([27], $sgr);
        $this->assertFalse($sgr->reverse);
    }

    public function testHiddenToggle(): void
    {
        $sgr = $this->apply([8]);
        $this->assertTrue($sgr->hidden);
        $sgr = $this->apply([28], $sgr);
        $this->assertFalse($sgr->hidden);
    }

    public function testStrikethroughToggle(): void
    {
        $sgr = $this->apply([9]);
        $this->assertTrue($sgr->strikethrough);
        $sgr = $this->apply([29], $sgr);
        $this->assertFalse($sgr->strikethrough);
    }

    public function testForeground16Color(): void
    {
        $sgr = $this->apply([31]); // red
        $this->assertNotNull($sgr->foreground);
        $this->assertSame(1, $sgr->foreground->kind);
        $this->assertSame(1, $sgr->foreground->value);
    }

    public function testBrightForegroundMapsTo816(): void
    {
        $sgr = $this->apply([91]); // bright red
        $this->assertSame(1, $sgr->foreground->kind);
        $this->assertSame(9, $sgr->foreground->value);
    }

    public function testForegroundDefault39(): void
    {
        $sgr = $this->apply([39], Sgr::empty()->withForeground(Color::indexed16(2)));
        $this->assertNotNull($sgr->foreground);
        $this->assertSame(0, $sgr->foreground->kind); // Default
    }

    public function testBackground16Color(): void
    {
        $sgr = $this->apply([42]); // green
        $this->assertSame(1, $sgr->background->kind);
        $this->assertSame(2, $sgr->background->value);
    }

    public function testBrightBackgroundMapsTo816(): void
    {
        $sgr = $this->apply([105]); // bright magenta
        $this->assertSame(1, $sgr->background->kind);
        $this->assertSame(13, $sgr->background->value);
    }

    public function testForeground256Color(): void
    {
        $sgr = $this->apply([38, 5, 196]);
        $this->assertSame(2, $sgr->foreground->kind);
        $this->assertSame(196, $sgr->foreground->value);
    }

    public function testBackground256Color(): void
    {
        $sgr = $this->apply([48, 5, 17]);
        $this->assertSame(2, $sgr->background->kind);
        $this->assertSame(17, $sgr->background->value);
    }

    public function testForegroundTruecolor(): void
    {
        $sgr = $this->apply([38, 2, 255, 128, 0]);
        $this->assertSame(3, $sgr->foreground->kind);
        $this->assertSame((255 << 16) | (128 << 8) | 0, $sgr->foreground->value);
    }

    public function testBackgroundTruecolor(): void
    {
        $sgr = $this->apply([48, 2, 0, 0, 255]);
        $this->assertSame(3, $sgr->background->kind);
        $this->assertSame(255, $sgr->background->value);
    }

    public function testCombinedParamsBoldRedFg(): void
    {
        $sgr = $this->apply([1, 31]);
        $this->assertTrue($sgr->bold);
        $this->assertSame(1, $sgr->foreground->value);
    }

    public function testTruecolorClampsOutOfRange(): void
    {
        // Parser already validates; SgrHandler treats anything in range as-is.
        $sgr = $this->apply([38, 2, 999, 999, 999]);
        $this->assertSame(3, $sgr->foreground->kind);
        $this->assertSame((255 << 16) | (255 << 8) | 255, $sgr->foreground->value);
    }

    public function testUnknown38SubFormSkipsMarker(): void
    {
        // 38 with neither 5 nor 2 → unknown; consume only the 38, continue with next.
        $sgr = $this->apply([38, 99, 1]);
        $this->assertTrue($sgr->bold); // 1 was processed after the unknown sub-form
    }

    public function testUnknownParamIgnored(): void
    {
        $sgr = $this->apply([200], Sgr::empty()->withBold(true));
        $this->assertTrue($sgr->bold); // unchanged
    }
}
