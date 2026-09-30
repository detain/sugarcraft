<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Mode\MouseEncoding;

final class ModeTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $m = new Mode();
        $this->assertFalse($m->altScreen);
        $this->assertTrue($m->cursorVisible);
        $this->assertFalse($m->bracketedPaste);
        $this->assertSame(MouseEncoding::Default, $m->mouseEncoding);
        $this->assertFalse($m->mouseAny);
        $this->assertFalse($m->mouseHighlights);
        $this->assertFalse($m->mouseCellMotion);
        $this->assertFalse($m->syncUpdate);
        $this->assertFalse($m->mouseExtended);
    }

    public function testWithAltScreen(): void
    {
        $m = (new Mode())->withAltScreen(true);
        $this->assertTrue($m->altScreen);
    }

    public function testWithCursorVisible(): void
    {
        $m = (new Mode())->withCursorVisible(false);
        $this->assertFalse($m->cursorVisible);
    }

    public function testWithMouseEncoding(): void
    {
        $m = (new Mode())->withMouseEncoding(MouseEncoding::Sgr);
        $this->assertSame(MouseEncoding::Sgr, $m->mouseEncoding);
    }

    public function testWithMouseHighlights(): void
    {
        $m = (new Mode())->withMouseHighlights(true);
        $this->assertTrue($m->mouseHighlights);
    }

    public function testWithMouseHighlightsDefaultTrue(): void
    {
        $m = (new Mode())->withMouseHighlights();
        $this->assertTrue($m->mouseHighlights);
    }

    public function testEquals(): void
    {
        $a = (new Mode())->withAltScreen(true)->withMouseEncoding(MouseEncoding::Sgr);
        $b = (new Mode())->withAltScreen(true)->withMouseEncoding(MouseEncoding::Sgr);
        $c = (new Mode())->withAltScreen(false)->withMouseEncoding(MouseEncoding::Sgr);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testDefaultAltScreenVariantIsNone(): void
    {
        $m = new Mode();
        $this->assertSame(Mode::ALT_NONE, $m->altScreenVariant);
        $this->assertFalse($m->altScreen);
        $this->assertFalse($m->isAltScreen());
    }

    public function testWithAltScreenVariantNoSave(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_NO_SAVE);
        $this->assertSame(Mode::ALT_NO_SAVE, $m->altScreenVariant);
        $this->assertTrue($m->altScreen);
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithAltScreenVariantCursorOnly(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_CURSOR_ONLY);
        $this->assertSame(Mode::ALT_CURSOR_ONLY, $m->altScreenVariant);
        $this->assertTrue($m->altScreen);
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithAltScreenVariantFull(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $this->assertSame(Mode::ALT_FULL, $m->altScreenVariant);
        $this->assertTrue($m->altScreen);
        $this->assertTrue($m->isAltScreen());
    }

    public function testWithAltScreenVariantNoneClearsAltScreen(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $this->assertTrue($m->altScreen);
        $m = $m->withAltScreenVariant(Mode::ALT_NONE);
        $this->assertSame(Mode::ALT_NONE, $m->altScreenVariant);
        $this->assertFalse($m->altScreen);
        $this->assertFalse($m->isAltScreen());
    }

    public function testWithAltScreenBoolSetsFullVariant(): void
    {
        $m = (new Mode())->withAltScreen(true);
        $this->assertSame(Mode::ALT_FULL, $m->altScreenVariant);
        $this->assertTrue($m->altScreen);

        $m = $m->withAltScreen(false);
        $this->assertSame(Mode::ALT_NONE, $m->altScreenVariant);
        $this->assertFalse($m->altScreen);
    }

    public function testIsAltScreenReturnsTrueWhenVariantActive(): void
    {
        $m = new Mode();
        $this->assertFalse($m->isAltScreen());

        $m = $m->withAltScreenVariant(Mode::ALT_NO_SAVE);
        $this->assertTrue($m->isAltScreen());

        $m = $m->withAltScreenVariant(Mode::ALT_CURSOR_ONLY);
        $this->assertTrue($m->isAltScreen());

        $m = $m->withAltScreenVariant(Mode::ALT_FULL);
        $this->assertTrue($m->isAltScreen());

        $m = $m->withAltScreenVariant(Mode::ALT_NONE);
        $this->assertFalse($m->isAltScreen());
    }

    public function testWithMouseEncodingPreservesAltScreenVariant(): void
    {
        $m = (new Mode())->withAltScreenVariant(Mode::ALT_FULL)->withMouseEncoding(MouseEncoding::Sgr);
        $this->assertSame(Mode::ALT_FULL, $m->altScreenVariant);
        $this->assertSame(MouseEncoding::Sgr, $m->mouseEncoding);
    }

    public function testIsMouseReportingPolarity(): void
    {
        // Reporting flows iff a TRACKING mode is on; the encoder slot alone
        // must never claim reporting (xterm emits nothing for 1005h alone).
        $this->assertFalse((new Mode())->isMouseReporting());
        $this->assertFalse((new Mode())->withMouseEncoding(MouseEncoding::Sgr)->isMouseReporting());
        foreach (['withMouseAny', 'withMouseHighlights', 'withMouseCellMotion', 'withMouseExtended'] as $setter) {
            $this->assertTrue((new Mode())->$setter(true)->isMouseReporting(), $setter);
        }
    }

    public function testEqualsDistinguishesEncoders(): void
    {
        $a = (new Mode())->withMouseEncoding(MouseEncoding::Utf8);
        $b = (new Mode())->withMouseEncoding(MouseEncoding::Urxvt);
        $this->assertFalse($a->equals($b));
        $this->assertTrue($a->equals((new Mode())->withMouseEncoding(MouseEncoding::Utf8)));
    }

    public function testEqualsIncludesAltScreenVariant(): void
    {
        $a = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $b = (new Mode())->withAltScreenVariant(Mode::ALT_FULL);
        $c = (new Mode())->withAltScreenVariant(Mode::ALT_NO_SAVE);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
