<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Color\Color;
use SugarCraft\Vterm\Handler\SgrHandler;
use SugarCraft\Vterm\Sgr\Sgr;

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

    public function testTruecolorOutOfRangeIgnoredWholeForm(): void
    {
        // xterm ignores an extended specification with any invalid byte
        // (256 colour index or truecolour channel): a bad `38;2` must not
        // clamp-paint 255;255;255 — the pen stays exactly as it was.
        $start = Sgr::empty()->withForeground(Color::indexed16(1));
        $sgr = $this->apply([38, 2, 999, 999, 999], $start);
        $this->assertNotNull($sgr->foreground);
        $this->assertSame(1, $sgr->foreground->value, 'invalid triplet leaves pen untouched');
        $partial = $this->apply([38, 2, 10, 20], $start);
        $this->assertNotNull($partial->foreground);
        $this->assertSame(1, $partial->foreground->value, 'incomplete triplet leaves pen untouched');
        $rgb = $this->apply([38, 2, 10, 20, 30])->foreground;
        $this->assertNotNull($rgb);
        $this->assertSame(3, $rgb->kind);
        $over = $this->apply([38, 5, 300], $start)->foreground;
        $this->assertNotNull($over);
        $this->assertSame(1, $over->value, 'over-range 256 index leaves pen untouched');
    }

    public function testMissing256IndexLeavesPenUntouched(): void
    {
        // `CSI 38;5m` with no index: not black paint — the form is ignored.
        $start = Sgr::empty()->withForeground(Color::indexed16(1));
        $fg = $this->apply([38, 5], $start)->foreground;
        $this->assertNotNull($fg);
        $this->assertSame(1, $fg->value);
        $bg = $this->apply([48, 5], $start->withBackground(Color::indexed16(2)))->background;
        $this->assertNotNull($bg);
        $this->assertSame(2, $bg->value);
    }

    public function testUnknown38SubFormIgnoresWholeForm(): void
    {
        // 38 with neither 5 nor 2 → the marker AND its invalid sub-form slot
        // are consumed (xterm ignores the whole extended specification); the
        // old skip-just-the-marker let `38;7` replay 7 as standalone reverse.
        $sgr = $this->apply([38, 7, 1]);
        $this->assertFalse($sgr->reverse, 'the swallowed 7 must not run as REVERSE');
        $this->assertTrue($sgr->bold, '1 after the ignored form still runs');
    }

    public function testUnknownParamIgnored(): void
    {
        $sgr = $this->apply([200], Sgr::empty()->withBold(true));
        $this->assertTrue($sgr->bold); // unchanged
    }
}
