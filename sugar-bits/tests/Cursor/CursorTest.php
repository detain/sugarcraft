<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\Cursor;

use CandyCore\Bits\Cursor\BlinkMsg;
use CandyCore\Bits\Cursor\Cursor;
use CandyCore\Bits\Cursor\Mode;
use CandyCore\Core\TickRequest;
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
}
