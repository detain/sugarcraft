<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Channel;

use PHPUnit\Framework\TestCase;
use SugarCraft\Wish\Channel\DefaultChannelHandler;
use SugarCraft\Wish\Channel\Msg\BreakMsg;
use SugarCraft\Wish\Channel\Msg\EnvMsg;
use SugarCraft\Wish\Channel\Msg\ExecMsg;
use SugarCraft\Wish\Channel\Msg\PtyReqMsg;
use SugarCraft\Wish\Channel\Msg\ShellMsg;
use SugarCraft\Wish\Channel\Msg\SignalMsg;
use SugarCraft\Wish\Channel\Msg\WindowChangeMsg;
use SugarCraft\Wish\Session;

final class DefaultChannelHandlerTest extends TestCase
{
    private function fakeSession(): Session
    {
        return new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm-256color', cols: 80, rows: 24, tty: '/dev/pts/0',
            command: null, lang: 'C.UTF-8',
        );
    }

    public function testHandlePtyReqTrueSetsPtyAllocated(): void
    {
        $handler = new DefaultChannelHandler();
        $msg = new PtyReqMsg(wantPty: true, term: 'xterm-256color', cols: 120, rows: 40);

        $handler->handlePtyReq($msg, $this->fakeSession());

        $this->assertTrue($handler->ptyAllocated());
        $this->assertSame(120, $handler->cols());
        $this->assertSame(40, $handler->rows());
    }

    public function testHandlePtyReqFalseClearsPtyAllocated(): void
    {
        $handler = new DefaultChannelHandler();
        $handler->handlePtyReq(new PtyReqMsg(wantPty: true, cols: 100, rows: 30), $this->fakeSession());
        $this->assertTrue($handler->ptyAllocated());

        $handler->handlePtyReq(new PtyReqMsg(wantPty: false), $this->fakeSession());

        $this->assertFalse($handler->ptyAllocated());
    }

    public function testHandlePtyReqWithZeroColsUsesSessionCols(): void
    {
        $handler = new DefaultChannelHandler();
        $session = new Session(
            user: 'alice', clientHost: '127.0.0.1', clientPort: 1, serverHost: '127.0.0.1',
            serverPort: 22, term: 'xterm', cols: 0, rows: 0, tty: null,
            command: null, lang: 'C',
        );

        $handler->handlePtyReq(new PtyReqMsg(wantPty: true, cols: 0, rows: 0), $session);

        $this->assertSame(80, $handler->cols());
        $this->assertSame(24, $handler->rows());
    }

    public function testHandleWindowChangeUpdatesColsAndRows(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleWindowChange(new WindowChangeMsg(cols: 160, rows: 50), $this->fakeSession());

        $this->assertSame(160, $handler->cols());
        $this->assertSame(50, $handler->rows());
    }

    public function testHandleWindowChangeWithZeroValuesFallsBack(): void
    {
        $handler = new DefaultChannelHandler();
        $handler->handleWindowChange(new WindowChangeMsg(cols: 0, rows: 0), $this->fakeSession());

        $this->assertSame(80, $handler->cols());
        $this->assertSame(24, $handler->rows());
    }

    public function testHandleShellSetsShellRequestedFalse(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleShell(new ShellMsg(wantShell: false), $this->fakeSession());

        $this->assertFalse($handler->shellRequested());
    }

    public function testHandleExecSetsExecRequestedTrue(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleExec(new ExecMsg('echo hello'), $this->fakeSession());

        $this->assertTrue($handler->execRequested());
        $this->assertSame(['echo', 'hello'], $handler->pendingCommand());
    }

    public function testHandleExecParsesSimpleCommand(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleExec(new ExecMsg('/bin/ls -la /tmp'), $this->fakeSession());

        $this->assertSame(['/bin/ls', '-la', '/tmp'], $handler->pendingCommand());
    }

    public function testHandleExecParsesQuotedCommand(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleExec(new ExecMsg("echo 'hello world'"), $this->fakeSession());

        $this->assertSame(['echo', 'hello world'], $handler->pendingCommand());
    }

    public function testHandleSignalIsNoOp(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleSignal(new SignalMsg('SIGINT'), $this->fakeSession());

        $this->assertNull($handler->ptyAllocated());
        $this->assertSame(80, $handler->cols());
        $this->assertSame(24, $handler->rows());
    }

    public function testHandleEnvCollectsEnvVars(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleEnv(new EnvMsg('FOO', 'bar'), $this->fakeSession());
        $handler->handleEnv(new EnvMsg('BAZ', 'qux'), $this->fakeSession());

        $this->assertSame(['FOO' => 'bar', 'BAZ' => 'qux'], $handler->envVars());
    }

    public function testHandleBreakIsNoOp(): void
    {
        $handler = new DefaultChannelHandler();

        $handler->handleBreak(new BreakMsg(breakLengthMs: 500), $this->fakeSession());

        $this->assertNull($handler->ptyAllocated());
        $this->assertFalse($handler->shellRequested());
        $this->assertFalse($handler->execRequested());
    }

    public function testDefaultHandlerHasNoPtyAllocatedInitially(): void
    {
        $handler = new DefaultChannelHandler();

        $this->assertNull($handler->ptyAllocated());
    }

    public function testDefaultHandlerHasDefaultColsRows(): void
    {
        $handler = new DefaultChannelHandler();

        $this->assertSame(80, $handler->cols());
        $this->assertSame(24, $handler->rows());
    }

    public function testEnvVarsEmptyInitially(): void
    {
        $handler = new DefaultChannelHandler();

        $this->assertSame([], $handler->envVars());
    }

    public function testPtyAllocatedFalseInitially(): void
    {
        $handler = new DefaultChannelHandler();

        $this->assertFalse($handler->shellRequested());
        $this->assertFalse($handler->execRequested());
    }
}
