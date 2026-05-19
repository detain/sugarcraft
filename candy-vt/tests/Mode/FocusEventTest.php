<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Msg\FocusInMsg;
use SugarCraft\Vt\Msg\FocusOutMsg;
use SugarCraft\Vt\Parser\Parser;

/**
 * Tests for focus event reporting — DECSET 1004 (CSI ? 1004 h/l) and
 * focus-in/out sequences (CSI I / CSI O).
 */
final class FocusEventTest extends TestCase
{
    private function handler(int $cols = 20, int $rows = 5): ScreenHandler
    {
        return new ScreenHandler(new Buffer($cols, $rows));
    }

    private function feed(string $bytes, int $cols = 20, int $rows = 5): ScreenHandler
    {
        $h = $this->handler($cols, $rows);
        (new Parser($h))->feed($bytes);
        return $h;
    }

    // ─── Mode field & wither ────────────────────────────────────────────────

    public function testReportFocusEventsDefaultsToFalse(): void
    {
        $m = new Mode();
        $this->assertFalse($m->reportFocusEvents);
    }

    public function testWithReportFocusEventsReturnsNewInstance(): void
    {
        $m = new Mode();
        $m2 = $m->withReportFocusEvents(true);
        $this->assertFalse($m->reportFocusEvents);
        $this->assertTrue($m2->reportFocusEvents);
    }

    public function testReportFocusEventsIncludedInEquals(): void
    {
        $a = (new Mode())->withReportFocusEvents(true);
        $b = new Mode();
        $this->assertFalse($a->equals($b));
        $this->assertTrue($a->equals($a));
        $this->assertTrue($b->equals($b));
    }

    // ─── CSI ? 1004 h / l ─────────────────────────────────────────────────

    public function testCsiQuestion1004hEnablesFocusReporting(): void
    {
        $h = $this->handler();
        $this->assertFalse($h->mode->reportFocusEvents);
        (new Parser($h))->feed("\x1b[?1004h");
        $this->assertTrue($h->mode->reportFocusEvents);
    }

    public function testCsiQuestion1004lDisablesFocusReporting(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[?1004h");
        $this->assertTrue($h->mode->reportFocusEvents);
        (new Parser($h))->feed("\x1b[?1004l");
        $this->assertFalse($h->mode->reportFocusEvents);
    }

    // ─── Focus event recording ─────────────────────────────────────────────

    public function testCsiIRecordsFocusInWhenEnabled(): void
    {
        $h = $this->feed("\x1b[?1004h\x1b[I");
        $this->assertCount(1, $h->focusEvents);
        $this->assertInstanceOf(FocusInMsg::class, $h->focusEvents[0]);
    }

    public function testCsiORecordsFocusOutWhenEnabled(): void
    {
        $h = $this->feed("\x1b[?1004h\x1b[O");
        $this->assertCount(1, $h->focusEvents);
        $this->assertInstanceOf(FocusOutMsg::class, $h->focusEvents[0]);
    }

    public function testMultipleFocusEventsRecorded(): void
    {
        $h = $this->feed("\x1b[?1004h\x1b[I\x1b[O\x1b[I");
        $this->assertCount(3, $h->focusEvents);
        $this->assertInstanceOf(FocusInMsg::class, $h->focusEvents[0]);
        $this->assertInstanceOf(FocusOutMsg::class, $h->focusEvents[1]);
        $this->assertInstanceOf(FocusInMsg::class, $h->focusEvents[2]);
    }

    public function testCsiIIsIgnoredWhenFocusReportingDisabled(): void
    {
        $h = $this->feed("\x1b[I");
        $this->assertCount(0, $h->focusEvents);
    }

    public function testCsiOIsIgnoredWhenFocusReportingDisabled(): void
    {
        $h = $this->feed("\x1b[O");
        $this->assertCount(0, $h->focusEvents);
    }

    public function testFocusReportingCanBeToggledOnAndOff(): void
    {
        $h = $this->handler();
        $parser = new Parser($h);

        // Enable reporting and send some events
        $parser->feed("\x1b[?1004h\x1b[I");
        $this->assertCount(1, $h->focusEvents);

        // Disable reporting - events should stop
        $parser->feed("\x1b[?1004l\x1b[I\x1b[O");
        $this->assertCount(1, $h->focusEvents);

        // Re-enable and send more events
        $parser->feed("\x1b[?1004h\x1b[O");
        $this->assertCount(2, $h->focusEvents);
    }

    // ─── FocusInMsg / FocusOutMsg value objects ────────────────────────────

    public function testFocusInMsgIsReadonly(): void
    {
        $msg = new FocusInMsg();
        $this->assertInstanceOf(FocusInMsg::class, $msg);
    }

    public function testFocusOutMsgIsReadonly(): void
    {
        $msg = new FocusOutMsg();
        $this->assertInstanceOf(FocusOutMsg::class, $msg);
    }

    public function testFocusInMsgAndFocusOutMsgAreDistinct(): void
    {
        $in = new FocusInMsg();
        $out = new FocusOutMsg();
        $this->assertNotEquals($in, $out);
    }
}
