<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Msg;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Msg\ForegroundColorMsg;
use SugarCraft\Core\ModeState;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\BlurMsg;
use SugarCraft\Core\Msg\ClipboardMsg;
use SugarCraft\Core\Msg\ColorProfileMsg;
use SugarCraft\Core\Msg\CursorPositionMsg;
use SugarCraft\Core\Msg\ExecMsg;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\InterruptMsg;
use SugarCraft\Core\Msg\ModeReportMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\PasteEndMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\PasteStartMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\ResumeMsg;
use SugarCraft\Core\Msg\SuspendMsg;
use SugarCraft\Core\Msg\TerminalVersionMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Util\ColorProfile;
use PHPUnit\Framework\TestCase;

final class MsgValueObjectsTest extends TestCase
{
    public function testFocusBlurMarkers(): void
    {
        $this->assertInstanceOf(Msg::class, new FocusGainedMsg());
        $this->assertInstanceOf(Msg::class, new BlurMsg());
    }

    public function testQuitInterruptSuspendResumeMarkers(): void
    {
        $this->assertInstanceOf(Msg::class, new QuitMsg());
        $this->assertInstanceOf(Msg::class, new InterruptMsg());
        $this->assertInstanceOf(Msg::class, new SuspendMsg());
        $this->assertInstanceOf(Msg::class, new ResumeMsg());
    }

    public function testPasteMarkers(): void
    {
        $start = new PasteStartMsg();
        $end = new PasteEndMsg();
        $body = new PasteMsg('hello\npasted');
        $this->assertSame('hello\npasted', $body->content);
        $this->assertInstanceOf(Msg::class, $start);
        $this->assertInstanceOf(Msg::class, $end);
    }

    public function testWindowSizeMsgFields(): void
    {
        $msg = new WindowSizeMsg(120, 40);
        $this->assertSame(120, $msg->cols);
        $this->assertSame(40, $msg->rows);
    }

    public function testCursorPositionMsgFields(): void
    {
        $msg = new CursorPositionMsg(5, 10);
        $this->assertSame(5, $msg->row);
        $this->assertSame(10, $msg->col);
    }

    public function testTerminalVersionMsgField(): void
    {
        $msg = new TerminalVersionMsg('xterm(367)');
        $this->assertSame('xterm(367)', $msg->version);
    }

    public function testClipboardMsgDefaults(): void
    {
        $msg = new ClipboardMsg('hello');
        $this->assertSame('hello', $msg->content);
        $this->assertSame('c', $msg->selection);
    }

    public function testClipboardMsgPrimarySelection(): void
    {
        $msg = new ClipboardMsg('content', 'p');
        $this->assertSame('p', $msg->selection);
    }

    public function testModeReportMsgFields(): void
    {
        $msg = new ModeReportMsg(2027, true, ModeState::Set);
        $this->assertSame(2027, $msg->mode);
        $this->assertTrue($msg->private);
        $this->assertSame(ModeState::Set, $msg->state);
    }

    public function testColorProfileMsgField(): void
    {
        $msg = new ColorProfileMsg(ColorProfile::TrueColor);
        $this->assertSame(ColorProfile::TrueColor, $msg->profile);
    }

    public function testExecMsgFields(): void
    {
        $msg = new ExecMsg(0, null, 'out', 'err');
        $this->assertSame(0, $msg->exitCode);
        $this->assertSame('out', $msg->stdout);
        $this->assertSame('err', $msg->stderr);
        $this->assertNull($msg->error);
        $this->assertTrue($msg->ok());
    }

    public function testExecMsgOkFalseOnNonZeroExit(): void
    {
        $msg = new ExecMsg(1);
        $this->assertFalse($msg->ok());
    }

    public function testExecMsgWithError(): void
    {
        $err = new \RuntimeException('boom');
        $msg = new ExecMsg(127, $err, '', 'not found');
        $this->assertSame(127, $msg->exitCode);
        $this->assertSame($err, $msg->error);
        $this->assertFalse($msg->ok());
    }

    public function testMouseClickMsgInheritsFields(): void
    {
        $msg = new MouseClickMsg(3, 4, MouseButton::Left, MouseAction::Press);
        $this->assertInstanceOf(MouseMsg::class, $msg);
        $this->assertSame(3, $msg->x);
        $this->assertSame(4, $msg->y);
        $this->assertSame(MouseButton::Left, $msg->button);
        $this->assertSame(MouseAction::Press, $msg->action);
    }

    public function testMouseReleaseMsgIsMouseMsg(): void
    {
        $msg = new MouseReleaseMsg(1, 1, MouseButton::Right, MouseAction::Release);
        $this->assertInstanceOf(MouseMsg::class, $msg);
        $this->assertSame(MouseButton::Right, $msg->button);
    }

    public function testMouseMotionMsgIsMouseMsg(): void
    {
        $msg = new MouseMotionMsg(10, 20, MouseButton::None, MouseAction::Motion);
        $this->assertInstanceOf(MouseMsg::class, $msg);
        $this->assertSame(MouseAction::Motion, $msg->action);
    }

    public function testMouseWheelMsgCarriesDirection(): void
    {
        $up = new MouseWheelMsg(0, 0, MouseButton::WheelUp, MouseAction::Press);
        $down = new MouseWheelMsg(0, 0, MouseButton::WheelDown, MouseAction::Press);
        $this->assertSame(MouseButton::WheelUp, $up->button);
        $this->assertSame(MouseButton::WheelDown, $down->button);
    }

    public function testMouseMsgModifiers(): void
    {
        $msg = new MouseMsg(
            0,
            0,
            MouseButton::Left,
            MouseAction::Press,
            shift: true,
            alt: true,
            ctrl: true
        );
        $this->assertTrue($msg->shift);
        $this->assertTrue($msg->alt);
        $this->assertTrue($msg->ctrl);
    }

    // sc-25 (finding sugar-core-046): isDark boundary set and ExecMsg
    // ok() conjunction arm.

    public function testBackgroundColorIsDarkBoundaryIsNaiveLinearFlip(): void
    {
        // The Msg colours mirror lipgloss v2's HasDarkBackground: naive
        // linear luminance, flip between 127 (dark) and 128 (light).
        $this->assertTrue((new BackgroundColorMsg(127, 127, 127))->isDark());
        $this->assertFalse((new BackgroundColorMsg(128, 128, 128))->isDark());
        $this->assertTrue((new ForegroundColorMsg(127, 127, 127))->isDark());
        $this->assertFalse((new ForegroundColorMsg(128, 128, 128))->isDark());
    }

    public function testColorIsDarkBoundaryIsWcagGammaFlip(): void
    {
        // Util\\Color documents WCAG 2.1 relative luminance instead: the
        // flip sits far higher (187 dark, 188 light for pure grey). The
        // two formulas are intentional, not a drift - mid greys around
        // #888888 are dark for Color and light for the Msg mirror.
        $this->assertTrue(Color::rgb(187, 187, 187)->isDark());
        $this->assertFalse(Color::rgb(188, 188, 188)->isDark());
        $this->assertTrue(Color::hex('#888888')->isDark());
        $this->assertFalse((new BackgroundColorMsg(136, 136, 136))->isDark());
    }

    public function testExecMsgOkFalseWhenErrorSetDespiteZeroExit(): void
    {
        // ok() is the conjunction error===null && exitCode===0: a launch
        // that reported 0 yet carried a Throwable must not read as success.
        $msg = new ExecMsg(0, new \RuntimeException('runner died'));
        $this->assertSame(0, $msg->exitCode);
        $this->assertFalse($msg->ok());
    }

}
