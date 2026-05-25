<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Cursor;

use SugarCraft\Forms\Cursor\BlinkMsg;
use SugarCraft\Forms\Cursor\Cursor;
use SugarCraft\Forms\Cursor\Mode;
use SugarCraft\Core\TickRequest;
use SugarCraft\Sprinkles\Style;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{
    public function testUnfocusedRendersPlain(): void
    {
        $c = Cursor::new('A');
        $this->assertSame('A', $c->view());
    }

    public function testFocusedBlinkOnRendersReverse(): void
    {
        [$c, ] = Cursor::new('A', Mode::Blink)->focus();
        $this->assertSame("\x1b[7mA\x1b[0m", $c->view());
    }

    public function testFocusReturnsBlinkCmd(): void
    {
        [, $cmd] = Cursor::new('A')->focus();
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(TickRequest::class, $cmd());
    }

    public function testStaticModeAlwaysHighlighted(): void
    {
        [$c, $cmd] = Cursor::new('A', Mode::Static)->focus();
        $this->assertNull($cmd); // no blink ticks for static mode
        $this->assertSame("\x1b[7mA\x1b[0m", $c->view());
    }

    public function testHiddenModeNeverHighlighted(): void
    {
        [$c, ] = Cursor::new('A', Mode::Hidden)->focus();
        $this->assertSame('A', $c->view());
    }

    public function testBlinkTogglesOnTick(): void
    {
        [$c, ] = Cursor::new('A', Mode::Blink)->focus();
        // Initial: blinkOn = true.
        $this->assertSame("\x1b[7mA\x1b[0m", $c->view());
        [$c, ] = $c->update(new BlinkMsg($c->id));
        $this->assertSame('A', $c->view());
        [$c, ] = $c->update(new BlinkMsg($c->id));
        $this->assertSame("\x1b[7mA\x1b[0m", $c->view());
    }

    public function testIgnoresBlinkForOtherCursor(): void
    {
        [$a, ] = Cursor::new('A')->focus();
        [$b, ] = Cursor::new('B')->focus();
        [$next, $cmd] = $a->update(new BlinkMsg($b->id));
        $this->assertSame($a, $next);
        $this->assertNull($cmd);
    }

    public function testBlurStopsHighlighting(): void
    {
        [$c, ] = Cursor::new('A')->focus();
        $c = $c->blur();
        $this->assertSame('A', $c->view());
    }

    public function testSetCharReplacesContent(): void
    {
        [$c, ] = Cursor::new('A')->focus();
        $c = $c->setChar('X');
        $this->assertSame("\x1b[7mX\x1b[0m", $c->view());
    }

    public function testIdAccessor(): void
    {
        $c = Cursor::new();
        $this->assertSame($c->id, $c->id());
    }

    public function testModeAccessor(): void
    {
        $c = Cursor::new('A', Mode::Static);
        $this->assertSame(Mode::Static, $c->mode());
    }

    public function testWithStyleOverridesHighlight(): void
    {
        [$c, ] = Cursor::new('A')->focus();
        $bold = Style::new()->bold();
        $c = $c->withStyle($bold)->setMode(Mode::Static);
        // Should use the supplied bold style, not reverse-video.
        $rendered = $c->view();
        $this->assertStringContainsString("\x1b[1mA\x1b[0m", $rendered);
        $this->assertStringNotContainsString("\x1b[7m", $rendered);
    }

    public function testWithTextStyleAppliesWhenOff(): void
    {
        // Hidden mode forces the off-state branch.
        $c = Cursor::new('A', Mode::Hidden);
        $faint = Style::new()->faint();
        $c = $c->withTextStyle($faint);
        // The cell renders via textStyle when not highlighted.
        $this->assertStringContainsString("\x1b[2mA\x1b[0m", $c->view());
    }

    public function testBlinkSpeedAccessor(): void
    {
        $c = Cursor::new('|', Mode::Blink, 0.25);
        $this->assertSame(0.25, $c->blinkSpeed());
    }

    public function testIsBlinkedFalseInStaticMode(): void
    {
        [$c, ] = Cursor::new('|', Mode::Static)->focus();
        $this->assertFalse($c->isBlinked());
    }

    public function testIsBlinkedTrueDuringOffPhase(): void
    {
        [$c, ] = Cursor::new('|', Mode::Blink)->focus();
        $this->assertFalse($c->isBlinked()); // initial = on
        // Toggle the blink phase via update().
        [$c, ] = $c->update(new BlinkMsg($c->id));
        $this->assertTrue($c->isBlinked());
    }
}
