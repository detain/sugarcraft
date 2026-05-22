<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\ExecRequest;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\InterruptMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Msg\SuspendMsg;
use SugarCraft\Core\PrintMsg;
use SugarCraft\Core\ProgressBarState;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\SequenceMsg;
use SugarCraft\Core\TickRequest;
use PHPUnit\Framework\TestCase;

final class CmdTest extends TestCase
{
    public function testQuitReturnsQuitMsg(): void
    {
        $cmd = Cmd::quit();
        $this->assertInstanceOf(QuitMsg::class, $cmd());
    }

    public function testSendWrapsMsg(): void
    {
        $msg = new class () implements Msg {};
        $this->assertSame($msg, Cmd::send($msg)());
    }

    public function testBatchReturnsBatchMsg(): void
    {
        $a = static fn () => null;
        $b = static fn () => null;
        $result = Cmd::batch($a, $b)();
        $this->assertInstanceOf(BatchMsg::class, $result);
        $this->assertCount(2, $result->cmds);
    }

    public function testBatchFiltersFalsy(): void
    {
        $a = static fn () => null;
        $result = Cmd::batch($a, null, $a)();
        $this->assertInstanceOf(BatchMsg::class, $result);
        $this->assertCount(2, $result->cmds);
    }

    public function testTickReturnsTickRequest(): void
    {
        $msg = new class () implements Msg {};
        $cmd = Cmd::tick(0.5, static fn () => $msg);
        $req = $cmd();
        $this->assertInstanceOf(\SugarCraft\Core\TickRequest::class, $req);
        $this->assertSame(0.5, $req->seconds);
        $this->assertSame($msg, ($req->produce)());
    }

    public function testRawWrapsBytesInRawMsg(): void
    {
        $cmd = Cmd::raw("\x1b]7;file:///tmp\x07");
        $msg = $cmd();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b]7;file:///tmp\x07", $msg->bytes);
    }

    public function testPrintlnWrapsTextInPrintMsg(): void
    {
        $cmd = Cmd::println('hello world');
        $msg = $cmd();
        $this->assertInstanceOf(PrintMsg::class, $msg);
        $this->assertSame('hello world', $msg->text);
    }

    public function testRequestCursorPositionEmitsDsrBytes(): void
    {
        $msg = (Cmd::requestCursorPosition())();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b[6n", $msg->bytes);
    }

    public function testRequestForegroundColorEmitsOsc10Query(): void
    {
        $msg = (Cmd::requestForegroundColor())();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b]10;?\x07", $msg->bytes);
    }

    public function testRequestBackgroundColorEmitsOsc11Query(): void
    {
        $msg = (Cmd::requestBackgroundColor())();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b]11;?\x07", $msg->bytes);
    }

    public function testRequestCursorColorEmitsOsc12Query(): void
    {
        $msg = (Cmd::requestCursorColor())();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b]12;?\x07", $msg->bytes);
    }

    public function testRequestTerminalVersionEmitsXtversionQuery(): void
    {
        $msg = (Cmd::requestTerminalVersion())();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b[>0q", $msg->bytes);
    }

    public function testRequestModePrivateBuildsDecrqm(): void
    {
        $msg = (Cmd::requestMode(1006))();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b[?1006\$p", $msg->bytes);
    }

    public function testRequestModeAnsiOmitsQuestionMark(): void
    {
        $msg = (Cmd::requestMode(4, private: false))();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b[4\$p", $msg->bytes);
    }

    public function testSetClipboardEncodesBase64(): void
    {
        $msg = (Cmd::setClipboard('hi'))();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b]52;c;" . base64_encode('hi') . "\x07", $msg->bytes);
    }

    public function testSetClipboardPrimarySelection(): void
    {
        $msg = (Cmd::setClipboard('hi', 'p'))();
        $this->assertSame("\x1b]52;p;" . base64_encode('hi') . "\x07", $msg->bytes);
    }

    public function testReadClipboardEmitsQueryWithDefaultSelection(): void
    {
        $msg = (Cmd::readClipboard())();
        $this->assertSame("\x1b]52;c;?\x07", $msg->bytes);
    }

    public function testSetWindowTitleEmitsOsc2(): void
    {
        $msg = (Cmd::setWindowTitle('hello'))();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b]2;hello\x07", $msg->bytes);
    }

    public function testSetWindowTitleIconFormEmitsOsc0(): void
    {
        $msg = (Cmd::setWindowTitle('hello', icon: true))();
        $this->assertSame("\x1b]0;hello\x07", $msg->bytes);
    }

    public function testSetWorkingDirectoryEmitsOsc7(): void
    {
        $msg = (Cmd::setWorkingDirectory('/home/foo', host: 'pluto'))();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b]7;file://pluto/home/foo\x07", $msg->bytes);
    }

    public function testSetWorkingDirectoryEncodesSpecialChars(): void
    {
        // Spaces and unicode get percent-escaped; '/' stays literal.
        $msg = (Cmd::setWorkingDirectory('/tmp/with space', host: 'h'))();
        $this->assertSame("\x1b]7;file://h/tmp/with%20space\x07", $msg->bytes);
    }

    public function testSetProgressBarNormal(): void
    {
        $msg = (Cmd::setProgressBar(ProgressBarState::Normal, 42))();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b]9;4;1;42\x07", $msg->bytes);
    }

    public function testSetProgressBarRemoveIgnoresPercent(): void
    {
        $msg = (Cmd::setProgressBar(ProgressBarState::Remove))();
        $this->assertSame("\x1b]9;4;0;0\x07", $msg->bytes);
    }

    public function testSetProgressBarClampsPercent(): void
    {
        $msgHigh = (Cmd::setProgressBar(ProgressBarState::Normal, 999))();
        $msgLow  = (Cmd::setProgressBar(ProgressBarState::Normal, -5))();
        $this->assertSame("\x1b]9;4;1;100\x07", $msgHigh->bytes);
        $this->assertSame("\x1b]9;4;1;0\x07", $msgLow->bytes);
    }

    public function testSetProgressBarIndeterminate(): void
    {
        $msg = (Cmd::setProgressBar(ProgressBarState::Indeterminate))();
        $this->assertSame("\x1b]9;4;3;0\x07", $msg->bytes);
    }

    public function testPushKittyKeyboardEmitsCsi(): void
    {
        $msg = (Cmd::pushKittyKeyboard(11))();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b[>11u", $msg->bytes);
    }

    public function testPopKittyKeyboardDefaultOne(): void
    {
        $msg = (Cmd::popKittyKeyboard())();
        $this->assertSame("\x1b[<1u", $msg->bytes);
    }

    public function testPopKittyKeyboardClampsBelowOne(): void
    {
        $msg = (Cmd::popKittyKeyboard(0))();
        $this->assertSame("\x1b[<1u", $msg->bytes);
    }

    public function testRequestKittyKeyboardEmitsQuery(): void
    {
        $msg = (Cmd::requestKittyKeyboard())();
        $this->assertSame("\x1b[?u", $msg->bytes);
    }

    public function testSuspendReturnsSuspendMsg(): void
    {
        $this->assertInstanceOf(SuspendMsg::class, (Cmd::suspend())());
    }

    public function testInterruptReturnsInterruptMsg(): void
    {
        $this->assertInstanceOf(InterruptMsg::class, (Cmd::interrupt())());
    }

    public function testSequenceReturnsSequenceMsg(): void
    {
        $a = static fn () => null;
        $b = static fn () => null;
        $msg = (Cmd::sequence($a, $b, null))();
        $this->assertInstanceOf(SequenceMsg::class, $msg);
        $this->assertCount(2, $msg->cmds);
    }

    public function testEveryReturnsTickRequest(): void
    {
        $msg = (Cmd::every(1.0, static fn () => null))();
        $this->assertInstanceOf(TickRequest::class, $msg);
        // delay should be <= 1 second (alignment to wall-clock).
        $this->assertLessThanOrEqual(1.0, $msg->seconds);
        $this->assertGreaterThanOrEqual(0.0, $msg->seconds);
    }

    public function testPrintfReturnsPrintMsg(): void
    {
        $msg = (Cmd::printf('hello %s %d', 'world', 42))();
        $this->assertInstanceOf(PrintMsg::class, $msg);
        $this->assertSame('hello world 42', $msg->text);
    }

    public function testExecReturnsExecRequest(): void
    {
        $cmd = Cmd::exec(['/bin/echo', 'hi'], captureOutput: true);
        $msg = $cmd();
        $this->assertInstanceOf(ExecRequest::class, $msg);
        $this->assertSame(['/bin/echo', 'hi'], $msg->command);
        $this->assertTrue($msg->captureOutput);
    }

    public function testEnterAltScreenEmitsAnsi(): void
    {
        $msg = (Cmd::enterAltScreen())();
        $this->assertInstanceOf(RawMsg::class, $msg);
        $this->assertSame("\x1b[?1049h", $msg->bytes);
    }

    public function testExitAltScreenEmitsAnsi(): void
    {
        $msg = (Cmd::exitAltScreen())();
        $this->assertSame("\x1b[?1049l", $msg->bytes);
    }

    public function testClearScreenEmitsHomeAndErase(): void
    {
        $msg = (Cmd::clearScreen())();
        $this->assertSame("\x1b[2J\x1b[1;1H", $msg->bytes);
    }

    public function testShowAndHideCursor(): void
    {
        $this->assertSame("\x1b[?25h", (Cmd::showCursor())()->bytes);
        $this->assertSame("\x1b[?25l", (Cmd::hideCursor())()->bytes);
    }

    public function testEnableMouseModes(): void
    {
        $cm = (Cmd::enableMouseCellMotion())();
        $this->assertStringContainsString("?1002h", $cm->bytes);
        $am = (Cmd::enableMouseAllMotion())();
        $this->assertStringContainsString("?1003h", $am->bytes);
        $off = (Cmd::disableMouse())();
        $this->assertStringContainsString("?1003l", $off->bytes);
        $this->assertStringContainsString("?1002l", $off->bytes);
    }

    public function testFocusReportingToggles(): void
    {
        $this->assertSame("\x1b[?1004h", (Cmd::enableReportFocus())()->bytes);
        $this->assertSame("\x1b[?1004l", (Cmd::disableReportFocus())()->bytes);
    }

    public function testBracketedPasteToggles(): void
    {
        $this->assertSame("\x1b[?2004h", (Cmd::enableBracketedPaste())()->bytes);
        $this->assertSame("\x1b[?2004l", (Cmd::disableBracketedPaste())()->bytes);
    }

    public function testScrollUpDownEmits(): void
    {
        $this->assertSame("\x1b[3S", (Cmd::scrollUp(3))()->bytes);
        $this->assertSame("\x1b[2T", (Cmd::scrollDown(2))()->bytes);
    }

    public function testSetForegroundColorEmitsOsc(): void
    {
        $msg = (Cmd::setForegroundColor(255, 128, 0))();
        $this->assertSame("\x1b]10;rgb:ff/80/00\x07", $msg->bytes);
    }

    public function testResetForegroundEmitsOsc110(): void
    {
        $this->assertSame("\x1b]110\x1b\\", (Cmd::resetForegroundColor())()->bytes);
        $this->assertSame("\x1b]111\x1b\\", (Cmd::resetBackgroundColor())()->bytes);
    }
}
