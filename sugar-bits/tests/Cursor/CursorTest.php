<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\Cursor;

use CandyCore\Bits\Cursor\BlinkMsg;
use CandyCore\Bits\Cursor\Cursor;
use CandyCore\Bits\Cursor\Mode;
use CandyCore\Core\TickRequest;
use CandyCore\Sprinkles\Style;
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
}
