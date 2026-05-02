<?php

declare(strict_types=1);

namespace CandyCore\Core\Tests;

use CandyCore\Core\BatchMsg;
use CandyCore\Core\Cmd;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\QuitMsg;
use CandyCore\Core\PrintMsg;
use CandyCore\Core\RawMsg;
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
        $msg = new class implements Msg {};
        $this->assertSame($msg, Cmd::send($msg)());
    }

    public function testBatchReturnsBatchMsg(): void
    {
        $a = static fn() => null;
        $b = static fn() => null;
        $result = Cmd::batch($a, $b)();
        $this->assertInstanceOf(BatchMsg::class, $result);
        $this->assertCount(2, $result->cmds);
    }

    public function testBatchFiltersFalsy(): void
    {
        $a = static fn() => null;
        $result = Cmd::batch($a, null, $a)();
        $this->assertInstanceOf(BatchMsg::class, $result);
        $this->assertCount(2, $result->cmds);
    }

    public function testTickReturnsTickRequest(): void
    {
        $msg = new class implements Msg {};
        $cmd = Cmd::tick(0.5, static fn() => $msg);
        $req = $cmd();
        $this->assertInstanceOf(\CandyCore\Core\TickRequest::class, $req);
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
}
